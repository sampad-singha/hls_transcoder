<?php

namespace App\Services;

use App\Jobs\TranscodeVideo;
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

        } catch (Exception $e) {
            Log::error("Pre-flight failed for {$rawPath}: " . $e->getMessage());
            // Inform your DB/User that the file is invalid
            throw new Exception($e->getMessage());
        }

        $videoData = [
            'video_id'           => $videoId,
            'file_path'          => $rawPath,
            'external_subtitles' => $subtitles,
            // ADD THESE TWO:
//            'callback_url'       => config('main_app_base_url'). "/api/v1/transcode/callback",
            'callback_url'       => "https://webhook.site/9f028353-e262-4bcc-991e-4ce3e7c8ad5f",
            'auth_token'         => request()->bearerToken(), // Captures the current user's/app's JWT
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
            // --- TODO: Update Database record for Video ID: $videoId ---
            // e.g., $video = Video::find($videoId); $video->update(['status' => 'ready']);

            // Remove the progress from cache to keep it clean
            cache()->forget($cacheKey);

            return 100;
        }

        return (int) $progress;
    }
}