# Auto Backup Guide

This panel includes an **Auto Backup** module for both:
- Local servers (`/server/:id`)
- External servers (`/server/external:CONNECTION_ID:SERVER_ID`)

The module creates panel backups on a schedule, then uploads completed archives to:
- Google Drive
- S3 bucket (AWS or S3-compatible)
- Dropbox

## 1. Open Auto Backup

1. Open a server in the panel.
2. Click **Auto Backup** in the server sidebar.

Permissions:
- Server owner and root admin can use it.
- Subusers need backup permissions (`backup.*`).

## Admin Global Setup

Root admin can configure global defaults at:

- `Admin > Settings > Auto Backups`

You can control:

- Global enable/disable for auto backup processing.
- Default destination/interval/keep values for new profiles.
- Shared provider credentials (Google Drive, S3, Dropbox).
- Whether users can override destination credentials per profile.

Secret fields behavior:

- Leave blank: keep existing secret.
- Enter `!clear`: remove the stored secret.

## 2. Create A Profile

Fill the form:
- **Name**: label for this auto-backup profile.
- **Destination**: Google Drive / S3 Bucket / Dropbox.
- **Interval (Minutes)**: how often to run.
- **Keep Remote Copies**: remote retention count.
- **Ignored Files**: one path per line (optional).
- **Enabled**: run scheduler for this profile.
- **Lock generated backups**: mark created backups as locked.

## 3. Destination Credentials

### Google Drive

Required:
- `client_id`
- `client_secret`
- `refresh_token`

Optional:
- `folder_id`

OAuth note:
- Use offline consent to obtain a refresh token.
- The module refreshes access tokens automatically during upload.

### S3 Bucket

Required:
- `bucket`
- `region`
- `access_key_id`
- `secret_access_key`

Optional:
- `endpoint` (for S3-compatible providers)
- `path_prefix`
- `use_path_style`

### Dropbox

Required:
- `access_token`

Optional:
- `folder_path`

## 4. Run And Verify

1. Click **Create Auto Backup**.
2. Click **Run Now** once to test.
3. Check status on the profile row:
   - `queued` -> backup started
   - `waiting` -> backup still processing
   - `uploaded` -> upload succeeded
   - `failed` -> check error text

## 5. Scheduler

The panel scheduler runs the processor every minute:

```bash
php artisan p:auto-backups:process --limit=20
```

Ensure:
- `php artisan schedule:work` is running or cron is configured for `schedule:run`.
- Queue worker (`pteroq`) is healthy.

## 6. External Server Support

External profiles are supported with the same UI and flow.

Requirements on the external panel/API key:
- backup creation access
- backup read/download access

If an external panel does not expose backup endpoints, runs will fail with a feature/endpoint error.

## 7. Retention Behavior

`Keep Remote Copies` controls remote destination cleanup.
- After each successful upload, old remote objects beyond the configured count are removed.
- Cleanup failures do not block successful uploads.

## 8. Troubleshooting

### Profile stays on `waiting`
- Backup is still being generated.
- Check server load and Wings backup state.

### `failed` with destination error
- Validate credentials and destination path/folder.
- For S3-compatible endpoints, toggle `use_path_style`.

### Google Drive token errors
- Regenerate `refresh_token` with offline access.
- Confirm OAuth client credentials match that token.

### External server backup errors
- Verify external API key permissions.
- Confirm external panel supports backup create/read/download routes.
