<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Models\TranscodingJob;
use Done\Subtitles\Code\Exceptions\UserException;
use Done\Subtitles\Subtitles;
use FFMpeg\Format\Video\X264;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Log;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Throwable;

class TranscodeVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300]; // For production, longer backoff times to allow transient issues to resolve
//    public array $backoff = [5, 10, 15]; // For testing, shorter backoff times

    public int $timeout = 7200; // 1 hour

    public function __construct(public $videoData)
    {
    }

    /**
     * @throws UserException
     * @throws ConnectionException
     * @throws Throwable
     */
    public function handle(): void
    {
        $attempt = TranscodingJob::create([
            'media_asset_id' => $this->videoData['media_asset_id'],
            'status' => 'processing',
            'started_at' => now(),
            'engine' => config('laravel-ffmpeg.gpu_codec', 'libx264'),
        ]);

        $asset = MediaAsset::find($this->videoData['media_asset_id']);
        $filePath = $this->videoData['file_path'];
        $externalSubs = $this->videoData['external_subtitles'] ?? [];
        $videoId = $this->videoData['external_id'];

        // 1. Open and Probe
        $media = FFMpeg::fromDisk('r2_raw')->open($filePath);
        $originalHeight = $media->getVideoStream()->getDimensions()->getHeight();
        $allProcessedSubs = [];

        try {
            // --- SUBTITLE HANDLING (Verified Working in your logs) ---
            foreach ($externalSubs as $sub) {
                $this->convertSrtToVtt($sub['path'], $videoId, $sub['lang']);
                $allProcessedSubs[] = [
                    'lang' => $sub['lang'],
                    'uri' => "sub_{$sub['lang']}.vtt"
                ];
            }

            foreach ($media->getStreams() as $stream) {
                if ($stream->get('codec_type') === 'subtitle') {
                    $lang = $stream->get('tags')['language'] ?? 'und';
                    $realIndex = $stream->get('index');
                    $this->extractEmbeddedSub($filePath, $realIndex, $videoId, $lang);
                    $allProcessedSubs[] = [
                        'lang' => $lang,
                        'uri' => "sub_{$lang}_$realIndex.vtt"
                    ];
                }
            }

            // --- VIDEO TRANSCODING (Simplified to avoid crashes) ---
            $export = FFMpeg::fromDisk('r2_raw')
                ->open($filePath)
                ->exportForHLS()
                ->onProgress(function ($percentage) use ($videoId) {
                    cache()->put("video_progress_$videoId", $percentage, now()->addHours(2));
                });

            $ladder = [
                ['height' => 360, 'width' => 640, 'bitrate' => 500],
                ['height' => 720, 'width' => 1280, 'bitrate' => 1500],
                ['height' => 1080, 'width' => 1920, 'bitrate' => 3000],
                ['height' => 2160, 'width' => 3840, 'bitrate' => 12000]
            ];

            $gpuCodec = config('laravel-ffmpeg.gpu_codec', 'libx264');

            foreach ($ladder as $level) {
                if ($originalHeight >= $level['height']) {

                    // 1. Create an "Unlocked" version of the X264 class
                    $format = new class extends X264 {
                        public function getAvailableVideoCodecs(): array
                        {
                            // This bypasses the validation error you were seeing
                            return ['libx264', 'h264_qsv', 'h264_nvenc', 'h264_amf'];
                        }
                    };

                    // 2. Apply your settings
                    $format->setVideoCodec($gpuCodec);
                    $format->setKiloBitrate($level['bitrate']);

                    // 3 UNIVERSAL HARDWARE RULES
                    $params = [];

                    // Rule A: Intel QuickSync (Windows/Linux)
                    if (str_contains($gpuCodec, 'qsv')) {
                        $params = ['-pix_fmt', 'nv12'];
                    }

                    // Rule B: Linux Generic Hardware (Intel/AMD)
                    // If it's VAAPI, it ALWAYS needs the device map and upload filter
                    if (str_contains($gpuCodec, 'vaapi')) {
                        $params = [
                            '-vaapi_device', '/dev/dri/renderD128',
                            '-vf', 'format=nv12,hwupload'
                        ];
                    }

                    // Rule C: NVIDIA (Windows/Linux)
                    // NVENC is very smart; it usually needs zero extra flags to work
                    if (str_contains($gpuCodec, 'nvenc')) {
                        $params = ['-preset', 'p4']; // High quality preset
                    }

                    if (!empty($params)) {
                        $format->setAdditionalParameters($params);
                    }

                    // 4. Use the $format variable here
                    $export->addFormat($format, function ($media) use ($level) {
                        $media->scale($level['width'], $level['height']);
                    });
                }
            }

            // 2. Save - The package will automatically name sub-playlists like master_0_500.m3u8
            $export->toDisk('r2_hls')->save($videoId . '/master.m3u8');

            // 3. Update manifest with ALL found subs (External + Embedded)
            $this->appendSubtitlesToManifest($videoId, $allProcessedSubs);

            $attempt->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);

            $asset->update([
                'status' => 'completed',
                'path' => "$videoId/master.m3u8",
                'duration' => $media->getDurationInSeconds(), // Final confirmed duration
                'resolution' => $media->getVideoStream()->getDimensions()->getWidth() . 'x' . $originalHeight,
                'bitrate' => $media->getFormat()->get('bit_rate'),
                'subtitle_tracks' => $allProcessedSubs
            ]);

            $this->notifyMainApp('completed');

        } catch (Throwable $e) {
            if ($asset) {
                $attempt->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => now()
                ]);
            }

            throw $e;
        }
    }

    /**
     * @throws ConnectionException
     */
    public function failed(Throwable $exception): void
    {
        $asset = MediaAsset::find($this->videoData['media_asset_id']);
        $videoId = $this->videoData['external_id'];

        // 1. Cleanup partial files in R2
        try {
            if (Storage::disk('r2_hls')->exists($videoId)) {
                Storage::disk('r2_hls')->deleteDirectory($videoId);
                Log::info("Cleaned up failed HLS directory: $videoId");
            }
        } catch (Throwable $cleanupError) {
            Log::error("Failed to cleanup HLS directory: " . $cleanupError->getMessage());
        }

        // 2. Update Asset Status
        if ($asset) {
            $asset->update([
                'status' => 'failed',
                'error_message' => 'Final Attempt Failed: ' . $exception->getMessage()
            ]);
        }

        $this->notifyMainApp('failed', $exception->getMessage());
    }

    private function extractEmbeddedSub($filePath, $streamIndex, $videoId, $lang): void
    {
        FFMpeg::fromDisk('r2_raw')
            ->open($filePath)
            ->export()
            ->addFilter(['-map', "0:$streamIndex"])
            ->toDisk('r2_hls')
            ->inFormat((new X264())->setAdditionalParameters(['-vn', '-an']))
            ->save("$videoId/sub_{$lang}_$streamIndex.vtt");
    }

    /**
     * @throws UserException
     */
    private function convertSrtToVtt($subPath, $videoId, $label): void
    {
        $srtContent = Storage::disk('r2_raw')->get($subPath);
        $subtitles = new Subtitles();
        $vttContent = $subtitles->loadFromString($srtContent, 'srt')->content('vtt');
        Storage::disk('r2_hls')->put("$videoId/sub_$label.vtt", $vttContent);
    }

    private function appendSubtitlesToManifest($videoId, $subs): void
    {
        $disk = Storage::disk('r2_hls');
        $masterPath = "$videoId/master.m3u8";

        if (!$disk->exists($masterPath) || empty($subs)) return;

        $content = $disk->get($masterPath);
        $subLines = "";

        foreach ($subs as $index => $sub) {
            $lang = $sub['lang'];
            $vttUri = $sub['uri'];
            $playlistName = "playlist_sub_{$lang}_$index.m3u8";

            // 1. Create the secondary subtitle playlist
            $this->createSubtitlePlaylist($videoId, $playlistName, $vttUri);

            // 2. Point the Master Manifest to the PLAYLIST, not the VTT
            $isDefault = ($index === 0) ? 'YES' : 'NO';
            $subLines .= "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subs\",NAME=\"$lang\",DEFAULT=$isDefault,AUTOSELECT=YES,FORCED=NO,LANGUAGE=\"$lang\",URI=\"$playlistName\"\n";
        }

        // Ensure VERSION:4 is present for subtitle support
        if (!str_contains($content, '#EXT-X-VERSION')) {
            $content = str_replace("#EXTM3U", "#EXTM3U\n#EXT-X-VERSION:4", $content);
        }

        $updatedContent = str_replace("#EXTM3U", "#EXTM3U\n" . $subLines, $content);

        // Link the group to the stream info
        $updatedContent = preg_replace('/#EXT-X-STREAM-INF:(.*)/', '#EXT-X-STREAM-INF:SUBTITLES="subs",$1', $updatedContent);

        $disk->put($masterPath, $updatedContent);
    }

    private function createSubtitlePlaylist($videoId, $playlistName, $vttUri): void
    {
        // This is the bridge file your examples showed
        $content = "#EXTM3U\n";
        $content .= "#EXT-X-VERSION:4\n";
        $content .= "#EXT-X-TARGETDURATION:10\n"; // Matches your video segment length
        $content .= "#EXT-X-MEDIA-SEQUENCE:0\n";
        $content .= "#EXT-X-PLAYLIST-TYPE:VOD\n";
        $content .= "#EXTINF:10.0,\n"; // Use a dummy value or actual duration
        $content .= $vttUri . "\n";
        $content .= "#EXT-X-ENDLIST";

        Storage::disk('r2_hls')->put("$videoId/$playlistName", $content);
    }

    /**
     * @throws ConnectionException
     */
    private function notifyMainApp(string $status, ?string $error = null): void
    {
        $callbackUrl = $this->videoData['callback_url'] ?? null;
        if (!$callbackUrl) return;

        // 1. Generate a FRESH token as the MS
        $payload = [
            'iss' => 'video-transcoder',       // The MS identifying itself
            'aud' => 'main-app',               // Intended for the Main App
            'iat' => time(),
            'exp' => time() + 60,              // Valid for only 60 seconds
            'video_id' => $this->videoData['external_id']
        ];

        $secret = config('services.internal_jwt_secret'); // Shared secret in both .env files
        $token = JWT::encode($payload, $secret, 'HS256');

        // 2. Send the request
        Http::withToken($token)
            ->timeout(10)
            ->retry(3, 100)
            ->post($callbackUrl, [
                'video_id' => $this->videoData['external_id'],
                'status' => $status,
                'path' => $status === 'completed' ? "{$this->videoData['external_id']}/master.m3u8" : null,
                'error' => $error,
            ]);
    }
}