<?php

namespace Pterodactyl\Http\Requests\Admin\Settings;

use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class AutoBackupsSettingsFormRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'auto_backups:enabled' => 'required|in:0,1',
            'auto_backups:allow_user_destination_override' => 'required|in:0,1',
            'auto_backups:default_destination_type' => 'required|in:google_drive,s3,dropbox',
            'auto_backups:default_interval_minutes' => 'required|integer|between:5,10080',
            'auto_backups:default_keep_remote' => 'required|integer|between:1,1000',

            'auto_backups:google_drive:auth_mode' => 'nullable|in:oauth,service_account',
            'auto_backups:google_drive:client_id' => 'nullable|string|max:512',
            'auto_backups:google_drive:client_secret' => 'nullable|string|max:4096',
            'auto_backups:google_drive:refresh_token' => 'nullable|string|max:4096',
            'auto_backups:google_drive:service_account_json' => 'nullable|string|max:30000',
            'auto_backups:google_drive:folder_id' => 'nullable|string|max:512',

            'auto_backups:s3:bucket' => 'nullable|string|max:255',
            'auto_backups:s3:region' => 'nullable|string|max:255',
            'auto_backups:s3:endpoint' => 'nullable|string|max:512',
            'auto_backups:s3:path_prefix' => 'nullable|string|max:512',
            'auto_backups:s3:use_path_style' => 'required|in:0,1',
            'auto_backups:s3:access_key_id' => 'nullable|string|max:512',
            'auto_backups:s3:secret_access_key' => 'nullable|string|max:4096',

            'auto_backups:dropbox:folder_path' => 'nullable|string|max:512',
            'auto_backups:dropbox:access_token' => 'nullable|string|max:4096',
        ];
    }

    public function attributes(): array
    {
        return [
            'auto_backups:enabled' => 'Auto Backups Enabled',
            'auto_backups:allow_user_destination_override' => 'Allow User Destination Override',
            'auto_backups:default_destination_type' => 'Default Destination Type',
            'auto_backups:default_interval_minutes' => 'Default Interval',
            'auto_backups:default_keep_remote' => 'Default Keep Remote',
            'auto_backups:google_drive:auth_mode' => 'Google Drive Auth Mode',
            'auto_backups:google_drive:client_id' => 'Google Drive Client ID',
            'auto_backups:google_drive:client_secret' => 'Google Drive Client Secret',
            'auto_backups:google_drive:refresh_token' => 'Google Drive Refresh Token',
            'auto_backups:google_drive:service_account_json' => 'Google Drive Service Account JSON',
            'auto_backups:google_drive:folder_id' => 'Google Drive Folder ID',
            'auto_backups:s3:bucket' => 'S3 Bucket',
            'auto_backups:s3:region' => 'S3 Region',
            'auto_backups:s3:endpoint' => 'S3 Endpoint',
            'auto_backups:s3:path_prefix' => 'S3 Path Prefix',
            'auto_backups:s3:use_path_style' => 'S3 Use Path Style',
            'auto_backups:s3:access_key_id' => 'S3 Access Key ID',
            'auto_backups:s3:secret_access_key' => 'S3 Secret Access Key',
            'auto_backups:dropbox:folder_path' => 'Dropbox Folder Path',
            'auto_backups:dropbox:access_token' => 'Dropbox Access Token',
        ];
    }
}
