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
        Schema::create('transcoding_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Link to the Asset. Since it's 1-to-Many,
            // multiple rows here = multiple attempts for one video.
            $table->foreignUuid('media_asset_id')->constrained('media_assets')->onDelete('cascade');

            // Internal status of THIS specific attempt
            $table->string('status')->default('pending');

            $table->string('engine')->nullable(); // libx264, h264_nvenc
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcoding_jobs');
    }
};
