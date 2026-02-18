<?php

namespace App\Services;

use App\Jobs\TranscodeVideo;
use Log;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class TranscodingService
{
    public function trigger(string $videoId, string $rawPath, array $subtitles = []): void
    {
        $videoData = [
            'video_id'           => $videoId,
            'file_path'          => $rawPath,
            'external_subtitles' => $subtitles,
            // ADD THESE TWO:
            'callback_url'       => config('main_app_base_url'). "/api/v1/transcode/callback",
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