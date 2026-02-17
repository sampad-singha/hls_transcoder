<?php

namespace App\Jobs;

use Done\Subtitles\Code\Exceptions\UserException;
use Done\Subtitles\Subtitles;
use FFMpeg\Format\Video\X264;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;
use Log;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class TranscodeVideo implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public $videoData)
    {
        //
    }

//    public function handle(): void
//    {
//        $filePath = $this->videoData['file_path'];
//
//        // 1. Probe the video first using a separate instance
//        $probe = FFMpeg::fromDisk('r2_raw')->open($filePath);
//        $dimensions = $probe->getVideoStream()->getDimensions();
//        $originalHeight = $dimensions->getHeight();
//
//        // 2. Define your bitrate ladder
//        $ladder = [
//            ['height' => 360,  'width' => 640,  'bitrate' => 500],
//            ['height' => 720,  'width' => 1280, 'bitrate' => 1500],
//            ['height' => 1080, 'width' => 1920, 'bitrate' => 3000],
//            ['height' => 2160, 'width' => 3840, 'bitrate' => 8000]
//        ];
//
//        // 3. Start a FRESH export instance
//        $export = FFMpeg::fromDisk('r2_raw')
//            ->open($filePath)
//            ->exportForHLS();
//
//        $addedFormats = 0;
//
//        foreach ($ladder as $level) {
//            // Only add if original is equal or better quality
//            if ($originalHeight >= $level['height']) {
//                $format = (new X264)->setKiloBitrate($level['bitrate']);
//
//                $export->addFormat($format, function ($media) use ($level) {
//                    // Use scale() for HLS advanced exports
//                    $media->scale($level['width'], $level['height']);
//                });
//                $addedFormats++;
//            }
//        }
//
//        // Fallback: If the video is smaller than 360p,
//        // we still need to add at least ONE format or HLS will fail.
//        if ($addedFormats === 0) {
//            $export->addFormat((new X264)->setKiloBitrate(500));
//        }
//
//        // 4. Save to disk
//        $export->toDisk('r2_hls')
//            ->save($this->videoData['video_id'] . '/master.m3u8');
//
//        Log::info("Transcoding complete for video_id: {$this->videoData['video_id']} (Original: {$originalHeight}p)");
//    }

    /**
     * Execute the job.
     * @throws ConnectionException
     */
    public function handle(): void
    {
        $filePath = $this->videoData['file_path'];
        $externalSubPath = $this->videoData['subtitle_path'] ?? null;
        $videoId = $this->videoData['video_id'];

        // 1. Open and Probe
        $media = FFMpeg::fromDisk('r2_raw')->open($filePath);
        $originalHeight = $media->getVideoStream()->getDimensions()->getHeight();

        // --- SUBTITLE HANDLING ---

        // Scenario 2: External Subtitle Provided
        if ($externalSubPath) {
            $this->convertSrtToVtt($externalSubPath, $videoId, 'external');
        }

        // Scenario 1: Embedded Subtitles (Extract ALL)
        foreach ($media->getStreams() as $index => $stream) {
            // Check if the codec_type is 'subtitle'
            if ($stream->get('codec_type') === 'subtitle') {
                $lang = $stream->get('tags')['language'] ?? 'und';

                // We need the ACTUAL stream index from the file for mapping
                // $stream->get('index') is safer than using the loop counter $index
                $realIndex = $stream->get('index');

                $this->extractEmbeddedSub($filePath, $realIndex, $videoId, $lang);
            }
        }

        // --- VIDEO TRANSCODING ---
        $export = FFMpeg::fromDisk('r2_raw')
            ->open($filePath)
            ->exportForHLS();

        $ladder = [
            ['height' => 360,  'width' => 640,  'bitrate' => 500],
            ['height' => 720,  'width' => 1280, 'bitrate' => 1500],
            ['height' => 1080, 'width' => 1920, 'bitrate' => 3000],
            ['height' => 2160, 'width' => 3840, 'bitrate' => 12000] // Better 4K bitrate
        ];

        $addedFormats = 0;
        foreach ($ladder as $level) {
            if ($originalHeight >= $level['height']) {
                $format = (new X264)->setKiloBitrate($level['bitrate']);
                $export->addFormat($format, function ($media) use ($level) {
                    $media->scale($level['width'], $level['height']);
                });
                $addedFormats++;
            }
        }

        if ($addedFormats === 0) {
            $export->addFormat((new X264)->setKiloBitrate(500));
        }

        $export->toDisk('r2_hls')->save($videoId . '/master.m3u8');
    }

    /**
     * Extraction helpers using the underlying FFmpeg driver
     */
    private function extractEmbeddedSub($filePath, $streamIndex, $videoId, $lang): void
    {
        FFMpeg::fromDisk('r2_raw')
            ->open($filePath)
            ->export()
            ->addFilter(['-map', "0:{$streamIndex}"]) // Select only the subtitle stream
            ->toDisk('r2_hls')
            // Use X264 but disable everything except the mapped stream
            ->inFormat((new X264())->setAdditionalParameters(['-vn', '-an']))
            ->save("{$videoId}/sub_{$lang}_{$streamIndex}.vtt");
    }

    /**
     * @throws UserException
     */
    private function convertSrtToVtt($subPath, $videoId, $label): void
    {
        // 1. Get the content from your R2 disk
        $srtContent = Storage::disk('r2_raw')->get($subPath);

        // 2. Instantiate and load (Use standard instance call, not static)
        $subtitles = new Subtitles();
        $vttContent = $subtitles->loadFromString($srtContent, 'srt')->content('vtt');

        // 3. Save the result to your HLS disk
        Storage::disk('r2_hls')->put("{$videoId}/sub_{$label}.vtt", $vttContent);

        Log::info("Subtitle converted via mantas-done instance: {$label}");
    }
}
