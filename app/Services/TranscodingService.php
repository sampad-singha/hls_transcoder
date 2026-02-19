<?php

namespace App\Services;

use App\Jobs\TranscodeVideo;
use App\Models\MediaAsset;
use Exception;
use Log;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class TranscodingService
{
    /**
     * @throws Exception
     */
    public function trigger(string $videoId, string $rawPath, array $subtitles = []): void
    {
        // 1. Check for existing asset by external_id
        $asset = MediaAsset::where('external_id', $videoId)->first();

        if ($asset) {
            // If it's already done or currently working, don't touch it.
            if (in_array($asset->status, ['completed', 'processing'])) {
                Log::info("Transcoding skipped for {$videoId}. Current status: {$asset->status}");
                return;
            }

            // If it reached here, it means the status is 'failed' or 'pending'.
            // We "Reset" the asset to prepare for a fresh attempt.
            $asset->update([
                'status'        => 'pending',
                'error_message' => null, // Clear the old error
            ]);
        } else {
            // 2. No existing asset? Create a brand new one.
            $asset = MediaAsset::create([
                'external_id' => $videoId,
                'status'      => 'pending',
                'disk'        => 'r2_raw'
            ]);
        }

        try {
            $probe = FFMpeg::fromDisk('r2_raw')->open($rawPath);
            $videoStream = $probe->getVideoStream();

            if (!$videoStream) {
                throw new Exception("File contains no valid video stream.");
            }

            // Optional: Check for minimum resolution or duration
            if ($probe->getDurationInSeconds() < 1) {
                throw new Exception("Video is too short to process.");
            }
            // Update asset with basic info before queuing
            $format = $probe->getFormat();

            $asset->update([
                'status'     => 'processing',
                'duration'   => $probe->getDurationInSeconds(),
                'mime_type'  => $format->get('format_name'),
                'bitrate'    => (int) $format->get('bit_rate'), // Now it's saved in the service
                'resolution' => $probe->getVideoStream()->getDimensions()->getWidth() . 'x' . $probe->getVideoStream()->getDimensions()->getHeight(),
            ]);

        } catch (Exception $e) {
            Log::error("Pre-flight failed for {$rawPath}: " . $e->getMessage());
            $asset->update([
                'status'        => 'failed',
                'error_message' => "Pre-flight: " . $e->getMessage()
            ]);

            throw new Exception($e->getMessage());
        }

        $videoData = [
            'media_asset_id'     => $asset->id, // Use internal UUID
            'external_id'        => $videoId,   // Use for folder naming
            'file_path'          => $rawPath,
            'external_subtitles' => $subtitles,
//            'callback_url'       => config('main_app_base_url'). "/api/v1/transcode/callback",
            'callback_url'       => "https://webhook.site/9f028353-e262-4bcc-991e-4ce3e7c8ad5f",
        ];

        TranscodeVideo::dispatch($videoData);

        Log::info("Transcoding Job Dispatched for Video: {$videoId}");
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getTranscodingProgress(string $videoId): int
    {
        $cacheKey = "video_progress_{$videoId}";
        $progress = cache()->get($cacheKey, -1);

        if ($progress >= 100) {
            // Remove the progress from cache to keep it clean
            cache()->forget($cacheKey);

            return 100;
        }

        return (int) $progress;
    }
}