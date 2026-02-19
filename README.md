# Video Transcoding Microservice (HLS + GPU)

A high-performance Laravel-based microservice designed to process raw video files into multi-bitrate HLS streams with
integrated subtitle support and hardware acceleration.

---

## 1. Requirements & Stack

* **PHP 8.2+** & **Laravel 11**
* **FFmpeg & FFprobe**: Must be installed on the host system.
* **GPU Drivers**: (Optional) Intel QuickSync (QSV), NVIDIA (NVENC), or VAAPI for hardware acceleration.
* **Redis**: Required for real-time progress tracking.
* **Object Storage**: S3-compatible storage (Cloudflare R2, AWS S3) for source and output.

---

## 2. Authentication (Service-to-Service)

This service uses **M2M (Machine-to-Machine) Symmetric JWT Authentication**. Both the Main App and the Transcoder must
share an `INTERNAL_JWT_SECRET`.

### JWT Handshake

1. **Main → Transcoder**: Requests must include a Bearer token signed with the shared secret.
2. **Transcoder → Main (Callback)**: Upon job completion/failure, the Transcoder generates a **new** short-lived (60s)
   JWT to notify the Main App.

**Standard Claims:**

* `iss`: `video-transcoder` or `main-app`
* `aud`: The receiving service name.
* `exp`: `iat + 60` (Short window prevents replay attacks).

---

## 3. The Transcoding Workflow

The service follows a strict pipeline to ensure data integrity and HLS compatibility.

### 3.1 Pre-flight Health Check

Before a job enters the queue, the `TranscodingService` performs a synchronous probe:

* **Integrity**: Runs `ffprobe` to ensure the file header isn't corrupted.
* **Stream Validation**: Confirms the existence of at least one valid video stream.
* **Duration Check**: Rejects files with $duration < 1s$.

### 3.2 Subtitle Processing

The engine handles two types of subtitle sources:

1. **External (SRT)**: Uses `Done\Subtitles` to convert `.srt` to `.vtt`.
2. **Embedded**: Uses `ffmpeg -map 0:s:{index}` to extract internal tracks.
3. **HLS v4 Wrapping**: Every `.vtt` file is wrapped in its own `.m3u8` playlist. This is required for HLS v4+
   compliance to ensure subtitles sync correctly with video segments.

### 3.3 Video Transcoding

* **Adaptive Ladder**: Generates variants (360p to 2160p) based on the source's original height (no upscaling).
* **Hardware Acceleration**: Applies specific FFmpeg parameters based on the configured `GPU_CODEC` (QSV, NVENC, or
  VAAPI).
* **Progress Tracking**: The `onProgress` callback updates Redis in real-time.

### 3.4 Manifest Injection

The final `master.m3u8` is post-processed to:

* Inject `#EXT-X-VERSION:4`.
* Define `#EXT-X-MEDIA:TYPE=SUBTITLES` groups.
* Link the `SUBTITLES="subs"` attribute to every `#EXT-X-STREAM-INF` video variant.

---

## 4. Database & Cache Logic

### 4.1 Redis Progress Tracking

Progress is tracked via a non-persistent cache to provide high-speed updates for frontend UI polling.

* **Cache Key**: `video_progress_{videoId}`
* **TTL**: 2 Hours (Prevents stale data from filling Redis if a job is abandoned).
* **Logic**: The value is an integer `0-100`. A value of `-1` indicates the job is not yet active.

### 4.2 Database Schema (Main App)

The `transcoding_jobs` table serves as the persistent audit log for every file processed.

| Column                 | Type      | Description                                      |
|:-----------------------|:----------|:-------------------------------------------------|
| `id`                   | UUID      | Primary Key (matches the `{videoId}`).           |
| `status`               | String    | `pending`, `processing`, `completed`, `failed`.  |
| `source_path`          | String    | Location of the raw file in storage.             |
| `destination_path`     | String    | Final path to the `master.m3u8` manifest.        |
| `duration`             | Integer   | Length of the video in seconds.                  |
| `resolution`           | String    | Original dimensions (e.g., `1920x1080`).         |
| `audio_track_count`    | Integer   | Total detected audio streams.                    |
| `subtitle_track_count` | Integer   | Total external + embedded subtitle streams.      |
| `engine_used`          | String    | The codec used for the job (e.g., `h264_nvenc`). |
| `started_at`           | Timestamp | When the job was dispatched.                     |
| `completed_at`         | Timestamp | When the callback was successfully received.     |

### 4.3 Data Consistency

1. **Trigger**: Main App creates the DB record as `pending`.
2. **Webhook**: The Transcoder's `notifyMainApp` call is the **source of truth**.
3. **Completion**: Upon `status: completed`, the Main App updates the DB and the UI stops polling Redis.
4. **Cleanup**: The `getTranscodingProgress` service method clears the Redis key once the 100% threshold is reached to
   maintain cache health.

---

## 5. API Endpoints

All endpoints require a valid **Authorization: Bearer {token}** signed with the shared secret using the HS256 algorithm.

### 5.1 POST /api/v1/transcode

Dispatches a new transcoding job. The source file must exist on the `r2_raw` disk before calling this endpoint.

**Request Body Fields:**

* **video_id** (UUID, Required): Unique identifier for the job and folder naming.
* **file_path** (String, Required): Path to the source file on the raw disk.
* **callback_url** (String, Required): The absolute URL the MS calls upon completion or failure.
* **external_subtitles** (Array, Optional): List of objects containing `lang` and `path`.

**Example Payload:**
```json
{
    "video_id": "fb1211f8-c461-4d42-a334-2080e770c3c8",
    "file_path": "uploads/raw/video_01.mp4",
    "callback_url": "[https://main-app.test/api/v1/transcode/callback](https://www.google.com/search?q=https://main-app.test/api/v1/transcode/callback)",
    "external_subtitles": [
        { "lang": "en", "path": "subtitles/english.srt" }
    ]
}
```

### 5.2 GET /api/v1/transcode/progress/{video_id}

Polls the real-time progress of an active transcoding job from the Redis cache.

**Response Body:**
```json
{
    "video_id": "fb1211f8-c461-4d42-a334-2080e770c3c8",
    "progress": 85
}
```

**Progress Logic:**

* **-1**: Job ID not found in cache (Idle or Expired).
* **0-99**: Job is currently being processed by the GPU/CPU.
* **100**: Transcoding and manifest generation are complete.

### 5.3 Callback Webhook (Inbound to Main App)

The Transcoder will perform a POST request to the provided `callback_url`. This request is signed with a fresh HS256 JWT
generated by the Transcoder.

**Example Success Payload:**

```json
{"video_id": "fb1211f8-c461-4d42-a334-2080e770c3c8",
    "status": "completed",
    "path": "fb1211f8.../master.m3u8",
    "tech_details": {
        "resolution": "1920x1080",
        "duration": 450,
        "audio_count": 2,
        "subtitle_count": 3,
        "engine": "h264_nvenc"
    }
}
```

---

## 6. JWT & Authentication

The service utilizes **Symmetric HS256 JWTs** for all service-to-service communication. This ensures that only the Main
App can start jobs, and only the Transcoder can send updates.

### 6.1 Shared Secret

Both applications must have the following key in their `.env`:
**INTERNAL_JWT_SECRET=your_secure_random_string**

### 6.2 Token Claims (Handshake)

To prevent replay attacks and ensure identity, tokens must follow this structure:

* **iss (Issuer)**: "main-app" (when calling MS) or "video-transcoder" (when calling Main).
* **aud (Audience)**: "video-transcoder" or "main-app" (identifies the recipient).
* **iat (Issued At)**: Current Unix timestamp.
* **exp (Expiration)**: iat + 60 (Tokens are only valid for 60 seconds).

### 6.3 Security Verification

The receiving service must manually verify the JWT before processing the request:

1. Verify the signature against the shared secret.
2. Ensure the `exp` claim has not passed.
3. Validate that the `aud` claim matches the receiving service name.

---

## 6. JWT & Authentication

The service utilizes **Symmetric HS256 JWTs** for all service-to-service communication. This ensures that only the Main App can start jobs, and only the Transcoder can send updates.

### 6.1 Shared Secret
Both applications must have the following key in their `.env`:
`INTERNAL_JWT_SECRET=your_long_random_string`

### 6.2 Token Structure (Handshake)
To prevent replay attacks and impersonation, the tokens follow this contract:

| Claim          | Key   | Value                                             |
|:---------------|:------|:--------------------------------------------------|
| **Issuer**     | `iss` | `main-app` (to MS) / `video-transcoder` (to Main) |
| **Audience**   | `aud` | `video-transcoder` / `main-app`                   |
| **Expiration** | `exp` | `iat + 60` (Tokens expire after 1 minute)         |



### 6.3 Callback Verification
The Main App's callback endpoint must verify:
1. **Signature**: The token was signed with the `INTERNAL_JWT_SECRET`.
2. **Audience**: The `aud` claim matches `main-app`.
3. **Expiration**: The current time is before the `exp` claim.

**MS Implementation Example:**
```php
$payload = [
    'iss'  => 'video-transcoder',
    'aud'  => 'main-app',
    'iat'  => time(),
    'exp'  => time() + 60,
    'video_id' => $videoId
];

$token = JWT::encode($payload, config('services.internal_secret'), 'HS256');
Http::withToken($token)->post($callbackUrl, $data);
```