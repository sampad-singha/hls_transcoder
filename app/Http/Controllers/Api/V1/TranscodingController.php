<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TranscodingService;
use Illuminate\Http\Request;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class TranscodingController extends Controller
{
    public function __construct(public TranscodingService $transcodingService)
    {
    }
    public function triggerTranscoding(Request $request)
    {
        // Validate that the Main App sent everything
        $data = $request->validate([
            'video_id' => 'required|string',
            'file_path' => 'required|string',
            'subtitles' => 'array' // Optional array of external subs
        ]);

        $this->transcodingService->trigger(
            $data['video_id'],
            $data['file_path'],
            $data['subtitles'] ?? []
        );

        return response()->json(['message' => 'Video is queued for transcoding.'], 202);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getProgress(string $videoId)
    {
        return response()->json([
            'video_id' => $videoId,
            'progress' => $this->transcodingService->getTranscodingProgress($videoId),
        ]);
    }
}
