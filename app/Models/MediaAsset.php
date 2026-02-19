<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MediaAsset extends Model
{
    use HasUuids;

    protected $fillable = [
        'external_id',
        'status',
        'disk',
        'path',
        'mime_type',
        'duration',
        'resolution',
        'bitrate',
        'audio_tracks',
        'subtitle_tracks',
        'error_message'
    ];

    protected $casts = [
        'audio_tracks' => 'array',
        'subtitle_tracks' => 'array',
        'duration' => 'integer',
        'bitrate' => 'integer',
    ];

    public function transcodingJobs(): HasMany
    {
        return $this->hasMany(TranscodingJob::class);
    }

    public function currentJob(): HasOne
    {
        return $this->hasOne(TranscodingJob::class)->latestOfMany();
    }
}
