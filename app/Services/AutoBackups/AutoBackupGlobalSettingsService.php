<?php

namespace Pterodactyl\Services\AutoBackups;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Arr;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

class AutoBackupGlobalSettingsService
{
    private const STORAGE_PREFIX = 'settings::auto_backups:';

    private const KEY_ENABLED = 'enabled';

    private const KEY_ALLOW_USER_OVERRIDE = 'allow_user_destination_override';

    private const KEY_DEFAULT_DESTINATION = 'default_destination_type';

    private const KEY_DEFAULT_INTERVAL = 'default_interval_minutes';

    private const KEY_DEFAULT_KEEP_REMOTE = 'default_keep_remote';

    private const KEY_GOOGLE_CLIENT_ID = 'google_drive:client_id';

    private const KEY_GOOGLE_AUTH_MODE = 'google_drive:auth_mode';

    private const KEY_GOOGLE_CLIENT_SECRET = 'google_drive:client_secret';

    private const KEY_GOOGLE_REFRESH_TOKEN = 'google_drive:refresh_token';

    private const KEY_GOOGLE_SERVICE_ACCOUNT_JSON = 'google_drive:service_account_json';

    private const KEY_GOOGLE_FOLDER_ID = 'google_drive:folder_id';

    private const KEY_S3_BUCKET = 's3:bucket';

    private const KEY_S3_REGION = 's3:region';

    private const KEY_S3_ENDPOINT = 's3:endpoint';

    private const KEY_S3_PATH_PREFIX = 's3:path_prefix';

    private const KEY_S3_USE_PATH_STYLE = 's3:use_path_style';

    private const KEY_S3_ACCESS_KEY_ID = 's3:access_key_id';

    private const KEY_S3_SECRET_ACCESS_KEY = 's3:secret_access_key';

    private const KEY_DROPBOX_FOLDER_PATH = 'dropbox:folder_path';

    private const KEY_DROPBOX_ACCESS_TOKEN = 'dropbox:access_token';

    private const SECRET_KEYS = [
        self::KEY_GOOGLE_CLIENT_SECRET,
        self::KEY_GOOGLE_REFRESH_TOKEN,
        self::KEY_GOOGLE_SERVICE_ACCOUNT_JSON,
        self::KEY_S3_SECRET_ACCESS_KEY,
        self::KEY_DROPBOX_ACCESS_TOKEN,
    ];

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Encrypter $encrypter
    ) {
    }

    /**
     * @return array{
     *   enabled:bool,
     *   allow_user_destination_override:bool,
     *   default_destination_type:string,
     *   default_interval_minutes:int,
     *   default_keep_remote:int,
     *   destinations:array{
     *      google_drive:array<string, mixed>,
     *      s3:array<string, mixed>,
     *      dropbox:array<string, mixed>
     *   },
     *   has_secrets:array<string, bool>
     * }
     */
    public function all(): array
    {
        $defaultDestination = (string) $this->get(self::KEY_DEFAULT_DESTINATION, 'google_drive');
        if (!in_array($defaultDestination, ['google_drive', 's3', 'dropbox'], true)) {
            $defaultDestination = 'google_drive';
        }

        $defaultInterval = max(5, min(10080, (int) $this->get(self::KEY_DEFAULT_INTERVAL, '360')));
        $defaultKeepRemote = max(1, min(1000, (int) $this->get(self::KEY_DEFAULT_KEEP_REMOTE, '10')));

        return [
            'enabled' => $this->toBool($this->get(self::KEY_ENABLED, '1')),
            'allow_user_destination_override' => $this->toBool($this->get(self::KEY_ALLOW_USER_OVERRIDE, '1')),
            'default_destination_type' => $defaultDestination,
            'default_interval_minutes' => $defaultInterval,
            'default_keep_remote' => $defaultKeepRemote,
            'destinations' => [
                'google_drive' => [
                    'auth_mode' => $this->sanitizeGoogleAuthMode((string) $this->get(self::KEY_GOOGLE_AUTH_MODE, 'oauth')),
                    'client_id' => (string) $this->get(self::KEY_GOOGLE_CLIENT_ID, ''),
                    'client_secret' => $this->getSecret(self::KEY_GOOGLE_CLIENT_SECRET),
                    'refresh_token' => $this->getSecret(self::KEY_GOOGLE_REFRESH_TOKEN),
                    'service_account_json' => $this->getSecret(self::KEY_GOOGLE_SERVICE_ACCOUNT_JSON),
                    'folder_id' => (string) $this->get(self::KEY_GOOGLE_FOLDER_ID, ''),
                ],
                's3' => [
                    'bucket' => (string) $this->get(self::KEY_S3_BUCKET, ''),
                    'region' => (string) $this->get(self::KEY_S3_REGION, ''),
                    'endpoint' => (string) $this->get(self::KEY_S3_ENDPOINT, ''),
                    'path_prefix' => (string) $this->get(self::KEY_S3_PATH_PREFIX, ''),
                    'use_path_style' => $this->toBool($this->get(self::KEY_S3_USE_PATH_STYLE, '0')),
                    'access_key_id' => (string) $this->get(self::KEY_S3_ACCESS_KEY_ID, ''),
                    'secret_access_key' => $this->getSecret(self::KEY_S3_SECRET_ACCESS_KEY),
                ],
                'dropbox' => [
                    'folder_path' => (string) $this->get(self::KEY_DROPBOX_FOLDER_PATH, ''),
                    'access_token' => $this->getSecret(self::KEY_DROPBOX_ACCESS_TOKEN),
                ],
            ],
            'has_secrets' => [
                self::KEY_GOOGLE_CLIENT_SECRET => $this->secretExists(self::KEY_GOOGLE_CLIENT_SECRET),
                self::KEY_GOOGLE_REFRESH_TOKEN => $this->secretExists(self::KEY_GOOGLE_REFRESH_TOKEN),
                self::KEY_GOOGLE_SERVICE_ACCOUNT_JSON => $this->secretExists(self::KEY_GOOGLE_SERVICE_ACCOUNT_JSON),
                self::KEY_S3_SECRET_ACCESS_KEY => $this->secretExists(self::KEY_S3_SECRET_ACCESS_KEY),
                self::KEY_DROPBOX_ACCESS_TOKEN => $this->secretExists(self::KEY_DROPBOX_ACCESS_TOKEN),
            ],
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) Arr::get($this->all(), 'enabled', true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateFromAdminPayload(array $payload): void
    {
        $this->set(self::KEY_ENABLED, $this->toBoolString($payload['auto_backups:enabled'] ?? '0'));
        $this->set(self::KEY_ALLOW_USER_OVERRIDE, $this->toBoolString($payload['auto_backups:allow_user_destination_override'] ?? '0'));
        $this->set(self::KEY_DEFAULT_DESTINATION, $this->sanitizeEnum((string) ($payload['auto_backups:default_destination_type'] ?? 'google_drive')));
        $this->set(self::KEY_DEFAULT_INTERVAL, (string) max(5, min(10080, (int) ($payload['auto_backups:default_interval_minutes'] ?? 360))));
        $this->set(self::KEY_DEFAULT_KEEP_REMOTE, (string) max(1, min(1000, (int) ($payload['auto_backups:default_keep_remote'] ?? 10))));

        $this->set(self::KEY_GOOGLE_AUTH_MODE, $this->sanitizeGoogleAuthMode((string) ($payload['auto_backups:google_drive:auth_mode'] ?? 'oauth')));
        $this->set(self::KEY_GOOGLE_CLIENT_ID, trim((string) ($payload['auto_backups:google_drive:client_id'] ?? '')));
        $this->set(self::KEY_GOOGLE_FOLDER_ID, trim((string) ($payload['auto_backups:google_drive:folder_id'] ?? '')));

        $this->set(self::KEY_S3_BUCKET, trim((string) ($payload['auto_backups:s3:bucket'] ?? '')));
        $this->set(self::KEY_S3_REGION, trim((string) ($payload['auto_backups:s3:region'] ?? '')));
        $this->set(self::KEY_S3_ENDPOINT, trim((string) ($payload['auto_backups:s3:endpoint'] ?? '')));
        $this->set(self::KEY_S3_PATH_PREFIX, trim((string) ($payload['auto_backups:s3:path_prefix'] ?? ''), '/'));
        $this->set(self::KEY_S3_USE_PATH_STYLE, $this->toBoolString($payload['auto_backups:s3:use_path_style'] ?? '0'));
        $this->set(self::KEY_S3_ACCESS_KEY_ID, trim((string) ($payload['auto_backups:s3:access_key_id'] ?? '')));

        $this->set(self::KEY_DROPBOX_FOLDER_PATH, trim((string) ($payload['auto_backups:dropbox:folder_path'] ?? ''), '/'));

        $this->setSecretFromPayload(self::KEY_GOOGLE_CLIENT_SECRET, $payload['auto_backups:google_drive:client_secret'] ?? null);
        $this->setSecretFromPayload(self::KEY_GOOGLE_REFRESH_TOKEN, $payload['auto_backups:google_drive:refresh_token'] ?? null);
        $this->setSecretFromPayload(self::KEY_GOOGLE_SERVICE_ACCOUNT_JSON, $payload['auto_backups:google_drive:service_account_json'] ?? null);
        $this->setSecretFromPayload(self::KEY_S3_SECRET_ACCESS_KEY, $payload['auto_backups:s3:secret_access_key'] ?? null);
        $this->setSecretFromPayload(self::KEY_DROPBOX_ACCESS_TOKEN, $payload['auto_backups:dropbox:access_token'] ?? null);
    }

    private function set(string $key, ?string $value): void
    {
        $this->settings->set($this->settingKey($key), $value ?? '');
    }

    private function get(string $key, string $default = ''): string
    {
        $value = $this->settings->get($this->settingKey($key), $default);

        return is_string($value) ? $value : (string) $value;
    }

    private function settingKey(string $suffix): string
    {
        return self::STORAGE_PREFIX . $suffix;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function toBoolString(mixed $value): string
    {
        return $this->toBool($value) ? '1' : '0';
    }

    private function sanitizeEnum(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['google_drive', 's3', 'dropbox'], true) ? $value : 'google_drive';
    }

    private function sanitizeGoogleAuthMode(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['oauth', 'service_account'], true) ? $value : 'oauth';
    }

    private function setSecretFromPayload(string $key, mixed $value): void
    {
        if (!is_string($value)) {
            return;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return;
        }

        if ($trimmed === '!clear') {
            $this->set($key, '');

            return;
        }

        $this->set($key, $this->encrypter->encrypt($trimmed));
    }

    private function getSecret(string $key): string
    {
        $raw = $this->get($key, '');
        if ($raw === '') {
            return '';
        }

        try {
            $decrypted = $this->encrypter->decrypt($raw);
        } catch (DecryptException) {
            return '';
        }

        return is_string($decrypted) ? $decrypted : '';
    }

    private function secretExists(string $key): bool
    {
        if (!in_array($key, self::SECRET_KEYS, true)) {
            return false;
        }

        return trim($this->getSecret($key)) !== '';
    }
}
