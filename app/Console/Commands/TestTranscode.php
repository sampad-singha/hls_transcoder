<?php

namespace App\Console\Commands;

use App\Jobs\TranscodeVideo;
use Illuminate\Console\Command;

class TestTranscode extends Command
{
    protected $signature = 'app:test-transcode';
    protected $description = 'Dispatches the TranscodeVideo job for testing';

    public function handle(): void
    {
        $this->info('Dispatching Transcode Job...');

        // Prepare the data your job expects
        $videoData = [
            'video_id' => 'test_folder',
            'file_path' => 'test5.mkv', // This file must exist in 'storage/app/raw_videos'
//            'subtitle_path' => 'sub_eng_2.srt', // This file must exist in 'storage/app/raw_videos'
        ];

        // Dispatch the job
        TranscodeVideo::dispatch($videoData);

        $this->info("Job successfully dispatched to the queue!");
        $this->comment("Make sure to run 'php artisan queue:work' to process it.");
    }
}