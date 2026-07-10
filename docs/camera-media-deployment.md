# Camera media deployment

Install libvips with JPEG, PNG, WebP, and libheif support. If libvips is unavailable, configure ImageMagick with the same delegates. The application falls back to guarded GD for JPEG, PNG, and WebP only.

Required production limits:

- `upload_max_filesize=20M`
- `post_max_size=22M`
- Web server request body limit of at least 20 MB
- Proxy request timeout of at least 90 seconds
- PHP execution timeout of at least 60 seconds

After deployment, call authenticated `GET /api/report-media/health`. Deployment is ready only when it returns HTTP 200 with `data.ready=true`. A 503 response means the processor, HEIC delegate, PHP limits, writable directories, or disk watermark is unsafe for camera traffic.

Operational requirements:

- Run `php artisan report-media:prune --hours=24` on schedule and alert when it fails.
- Alert when free space approaches `REPORT_MEDIA_MINIMUM_DISK_FREE_BYTES`.
- Keep `REPORT_MEDIA_TEMPORARY_USER_QUOTA_BYTES` above the 12 MB report limit but low enough to prevent abandoned-upload abuse.
- Keep thumbnail generation enabled; forms and galleries use private 480 px previews to bound mobile decode memory.
- Treat `upload_busy`, `storage_quota_exceeded`, `storage_unavailable`, decode failures, timeouts, and retry rates as camera health metrics.
- Verify the proxy preserves JSON error responses for 413 and timeout failures.
