# DriveBackupV2 Setup (Local + External Servers)

This setup uses DriveBackupV2 plugin backups, not Pterodactyl panel backup slots.

## Does It Work Without Backup Slots?

Yes.

- DriveBackupV2 runs inside the Minecraft server.
- It backs up server files directly to Google Drive.
- It does **not** depend on `backup_limit` in Pterodactyl.
- This is why it works for external servers that have no panel backup slot support.

## Pre-staged Plugin File

Plugin jar is available at:

- `https://panel.xentranetwork.me/DriveBackupV2-v1.8.1.jar`

SHA256:

- `c531e8fb6dc446fc710c59440c33ca2c71f15ec3933487cf4ae266b5617c591f`

## Install On A Server

1. Open server file manager.
2. Upload/copy `DriveBackupV2-v1.8.1.jar` into `plugins/`.
3. Restart server.
4. Confirm plugin loaded in console logs.

For external panels:

- Do the same steps on the external panel file manager (or SFTP).
- No panel backup API is required.

## Link Google Drive

Run in server console:

- `/drivebackup linkaccount googledrive`

Then follow the generated link and complete auth.

After linking, run:

- `/drivebackup backup`

to perform a test backup immediately.

## Recommended Basic Config

After first run, edit:

- `plugins/DriveBackupV2/config.yml`

Set backup interval/retention and included paths as needed.

## Troubleshooting

- If Google auth fails, verify system time is correct on the node.
- If upload fails with permissions, re-link account and retry.
- If plugin doesn’t load, verify Java version is compatible with your server build.
