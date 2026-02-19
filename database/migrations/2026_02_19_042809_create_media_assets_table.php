<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            // Primary Key as UUID (Internal to MS)
            $table->uuid('id')->primary();

            // The UUID from the Main App (for folder naming and lookups)
            $table->uuid('external_id')->unique()->index();

            // Lifecycle: pending, processing, completed, failed
            $table->string('status')->default('pending');

            // Storage & Access
            $table->string('disk')->default('r2_hls');
            $table->string('path')->nullable(); // path/to/master.m3u8

            // Technical Metadata
            $table->string('mime_type')->nullable();
            $table->integer('duration')->default(0);
            $table->string('resolution')->nullable();
            $table->integer('bitrate')->nullable();
            $table->json('audio_tracks')->nullable();
            $table->json('subtitle_tracks')->nullable();

            // Error Logging (for corruption or FFmpeg crashes)
            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
