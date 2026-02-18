# Video Transcoding Microservice (HLS)

## Core Technologies
* **PHP 8.2+** / **Laravel 11**
* **FFmpeg** (with `libx264` and `webvtt` support)
* **ProtoneMedia/Laravel-FFMpeg**
* **Redis** (Recommended for progress tracking)
* **Cloudflare R2 / S3** (Storage)

---

## 1. Setup Requirements
* **FFmpeg Binaries**: Ensure `ffmpeg` and `ffprobe` are installed on the OS.
* **Queue Worker**: A dedicated worker must be running:
  `php artisan queue:work --queue=default`
* **Cache Store**: Set `CACHE_STORE=redis` in `.env` for real-time progress sharing between the Job and API.

### 1.1 Security & Auth
* **JWT Shared Secret**: Both Main App and Service must share `INTERNAL_JWT_SECRET` in `.env`.
* **Middleware**: All API routes are protected by the `internal.jwt` middleware.
* **Postman**: Uses a Pre-request script to generate a Bearer token signed with the shared secret.
---

## 2. Video Encoding Ladder
The service dynamically scales down based on the source height. It will not upscale.

| Resolution | Label | Bitrate (kbps) | Dimensions |
| :--- | :--- | :--- | :--- |
| **4K** | 2160p | 12000 | 3840x2160 |
| **1080p** | FHD | 3000 | 1920x1080 |
| **720p** | HD | 1500 | 1280x720 |
| **360p** | SD | 500 | 640x360 |

---

## 3. The Transcoding Workflow
The service processes a raw video into a multi-bitrate HLS stream with VTT subtitles.



1.  **Input**: Receives raw file path and optional external SRT paths.
2.  **Subtitles**:
    * Converts SRT to VTT using `Done\Subtitles\Subtitles`.
    * Extracts embedded subtitles using `ffmpeg -map 0:s:{index}`.
    * **Crucial**: Every `.vtt` is wrapped in its own `.m3u8` subtitle playlist (HLS v4 spec) to ensure player synchronization.
3.  **Video**: Creates an HLS ladder (360p to 2160p) via `exportForHLS()`.
4.  **Manifest Update**:
    * Injects `#EXT-X-MEDIA:TYPE=SUBTITLES` into `master.m3u8`.
    * Links the subtitle `GROUP-ID` to video variants via the `SUBTITLES="subs"` attribute in the `#EXT-X-STREAM-INF` lines.

---

## 4. API Endpoints

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| **POST** | `/api/v1/transcode` | Dispatches `TranscodeVideo` job. |
| **GET** | `/api/v1/transcode/progress/{uuid}` | Returns integer `-1` (idle), `0-99` (active), or `100` (done). |

---

## 5. Database & Cache Logic
* **Cache Key**: `video_progress_{uuid}`
* **Progress Tracking**: Updated via the `onProgress` callback during the export.
* **Cleanup**: The Service Method automatically calls `cache()->forget($key)` once progress reaches `100` to prevent stale data.
* **Persistence**: **(TODO)** Implement a database update (e.g., `$video->update(['status' => 'ready'])`) inside the `getTranscodingProgress` check when the value first hits 100.

---

## 6. Troubleshooting / Common Issues
* **No Subtitles in Player**:
    * Ensure `master.m3u8` contains the tag `#EXT-X-VERSION:4` at the top.
    * Verify the `URI` in `#EXT-X-MEDIA` points to the **subtitle playlist** (`.m3u8`), **not** the raw `.vtt` file.
* **Stuck at 0%**:
    * Verify the `queue:work` process is active.
    * Check Redis connectivity if using the Redis cache driver.
* **Jittery/Resetting Progress**:
    * Ensure the `onProgress` logic includes a check to only update the cache if the new percentage is greater than the existing cached value (prevents resets when switching bitrate rungs).

---

## 7. Postman Monitoring (Loop Script)
Paste in **Post-res** tab and run via **Collection Runner**:

```javascript
const progress = parseInt(pm.response.json().progress);
if (progress >= 0 && progress < 100) {
    pm.execution.setNextRequest(pm.info.requestName);
} else {
    pm.execution.setNextRequest(null);
}
````
---

## 8. JWT & API Payload Structure

### 8.1 JWT Claims (Authentication)

The `Authorization: Bearer {token}` header must be signed using `HS256` with the shared secret.

| Claim | Key | Value | Description |
| --- | --- | --- | --- |
| **Issuer** | `iss` | `main-app` | Standard identifier for the calling service. |
| **Issued At** | `iat` | `timestamp` | Unix timestamp when the token was created. |
| **Expiration** | `exp` | `iat + 60` | Short-lived window (60s) to prevent replay attacks. |
| **Audience** | `aud` | `video-transcoder` | Identifies the intended recipient of the token. |

### 8.2 API Request Payloads

The following structures define the communication between the Main App and the HLS Service.

**POST** `/api/v1/transcode`

```json
{
    "video_id": "fb1211f8-c461-4d42-a334-2080e770c3c8",
    "source_path": "uploads/raw/video_01.mp4",
    "subtitles": [
        {
            "lang": "en",
            "path": "subtitles/english.srt",
            "type": "external"
        },
        {
            "lang": "fr",
            "stream_index": 2,
            "type": "embedded"
        }
    ]
}

```

**GET** `/api/v1/transcode/progress/{video_id}`

```json
{
    "video_id": "fb1211f8-c461-4d42-a334-2080e770c3c8",
    "progress": 85,
    "status": "processing"
}

```

### 8.3 HLS Output Structure

Upon reaching `100%`, the generated file tree on the storage disk follows this pattern:

```text
/transcoded/{video_id}/
├── master.m3u8           # Multi-variant master playlist
├── 360p.m3u8             # SD Video playlist
├── 720p.m3u8             # HD Video playlist
├── 1080p.m3u8            # FHD Video playlist
├── subs_en.m3u8          # English Subtitle playlist (VTT)
├── subs_fr.m3u8          # French Subtitle playlist (VTT)
└── segments/             # TS and VTT data chunks
    ├── stream_0_001.ts
    └── en_001.vtt

```
