<?php

namespace Pterodactyl\Services\AutoBackups;

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use RuntimeException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\AutoBackupProfile;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Backups\DownloadLinkService;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Services\External\ExternalServerReference;
use Pterodactyl\Services\External\ExternalServerRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AutoBackupManagerService
{
    public function __construct(
        private AutoBackupDestinationUploader $destinationUploader,
        private InitiateBackupService $initiateBackupService,
        private DownloadLinkService $downloadLinkService,
        private ExternalServerRepository $externalServerRepository,
        private AutoBackupGlobalSettingsService $globalSettings
    ) {
    }

    /**
     * @return array<int, AutoBackupProfile>
     */
    public function listProfilesForServer(User $user, string $serverIdentifier): array
    {
        $identifier = $this->normalizeServerIdentifier($serverIdentifier);

        return AutoBackupProfile::query()
            ->where('user_id', $user->id)
            ->where('server_identifier', $identifier)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    /**
     * @return array{
     *   enabled:bool,
     *   allow_user_destination_override:bool,
     *   default_destination_type:string,
     *   default_interval_minutes:int,
     *   default_keep_remote:int
     * }
     */
    public function clientDefaults(): array
    {
        $global = $this->globalSettings->all();

        return [
            'enabled' => (bool) Arr::get($global, 'enabled', true),
            'allow_user_destination_override' => (bool) Arr::get($global, 'allow_user_destination_override', true),
            'default_destination_type' => (string) Arr::get($global, 'default_destination_type', 'google_drive'),
            'default_interval_minutes' => (int) Arr::get($global, 'default_interval_minutes', 360),
            'default_keep_remote' => (int) Arr::get($global, 'default_keep_remote', 10),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createProfile(User $user, string $serverIdentifier, array $payload): AutoBackupProfile
    {
        $this->assertAutoBackupsEnabled();

        $identifier = $this->normalizeServerIdentifier($serverIdentifier);
        $this->assertServerAccessible($user, $identifier);

        $normalized = $this->normalizePayload($payload);
        $destinationConfig = $normalized['destination_config'] ?? [];
        unset($normalized['destination_config']);

        $profile = new AutoBackupProfile();
        $profile->fill(array_merge($normalized, [
            'user_id' => $user->id,
            'server_identifier' => $identifier,
            'next_run_at' => CarbonImmutable::now()->addMinutes($normalized['interval_minutes']),
            'last_status' => 'idle',
        ]));
        $profile->destination_config = is_array($destinationConfig) ? $destinationConfig : [];
        $profile->save();

        return $profile->refresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateProfile(User $user, string $serverIdentifier, int $profileId, array $payload): AutoBackupProfile
    {
        $identifier = $this->normalizeServerIdentifier($serverIdentifier);

        $profile = $this->getOwnedProfile($user, $identifier, $profileId);
        $normalized = $this->normalizePayload($payload, $profile);
        $destinationConfig = $normalized['destination_config'] ?? [];
        unset($normalized['destination_config']);

        $profile->fill($normalized);
        $profile->destination_config = is_array($destinationConfig) ? $destinationConfig : [];

        if (array_key_exists('interval_minutes', $normalized) && is_null($profile->pending_backup_uuid)) {
            $profile->next_run_at = CarbonImmutable::now()->addMinutes((int) $profile->interval_minutes);
        }

        if (!$profile->is_enabled) {
            $profile->pending_backup_uuid = null;
            $profile->next_run_at = null;
            $profile->last_status = 'disabled';
        } elseif (is_null($profile->next_run_at)) {
            $profile->next_run_at = CarbonImmutable::now()->addMinutes((int) $profile->interval_minutes);
        }

        $profile->save();

        return $profile->refresh();
    }

    public function deleteProfile(User $user, string $serverIdentifier, int $profileId): void
    {
        $identifier = $this->normalizeServerIdentifier($serverIdentifier);
        $profile = $this->getOwnedProfile($user, $identifier, $profileId);
        $profile->delete();
    }

    public function triggerNow(User $user, string $serverIdentifier, int $profileId): AutoBackupProfile
    {
        $this->assertAutoBackupsEnabled();

        $identifier = $this->normalizeServerIdentifier($serverIdentifier);
        $profile = $this->getOwnedProfile($user, $identifier, $profileId);
        $this->processProfile($profile, true);

        return $profile->refresh();
    }

    /**
     * @return array{processed:int,queued:int,uploaded:int,failed:int,skipped:int}
     */
    public function processDueProfiles(int $limit = 20): array
    {
        $stats = [
            'processed' => 0,
            'queued' => 0,
            'uploaded' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        if (!$this->globalSettings->isEnabled()) {
            return $stats;
        }

        $profiles = AutoBackupProfile::query()
            ->where('is_enabled', true)
            ->where(function ($query) {
                $query->whereNotNull('pending_backup_uuid')
                    ->orWhereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', CarbonImmutable::now());
            })
            ->orderBy('next_run_at')
            ->limit(max(1, $limit))
            ->get();

        foreach ($profiles as $profile) {
            $stats['processed']++;

            $result = $this->processProfile($profile);
            $status = $result->last_status ?? '';

            if ($status === 'queued') {
                $stats['queued']++;
            } elseif ($status === 'uploaded') {
                $stats['uploaded']++;
            } elseif ($status === 'failed') {
                $stats['failed']++;
            } else {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    public function toApiResource(AutoBackupProfile $profile): array
    {
        return [
            'object' => AutoBackupProfile::RESOURCE_NAME,
            'attributes' => [
                'id' => $profile->id,
                'server_identifier' => $profile->server_identifier,
                'name' => $profile->name,
                'destination_type' => $profile->destination_type,
                'destination_config' => $this->sanitizedDestinationConfig($profile->destination_type, $profile->destination_config),
                'is_enabled' => $profile->is_enabled,
                'interval_minutes' => $profile->interval_minutes,
                'keep_remote' => $profile->keep_remote,
                'is_locked' => $profile->is_locked,
                'ignored_files' => $profile->ignored_files ?? '',
                'pending_backup_uuid' => $profile->pending_backup_uuid,
                'last_backup_uuid' => $profile->last_backup_uuid,
                'last_status' => $profile->last_status,
                'last_error' => $profile->last_error,
                'last_run_at' => optional($profile->last_run_at)?->toAtomString(),
                'next_run_at' => optional($profile->next_run_at)?->toAtomString(),
                'created_at' => optional($profile->created_at)?->toAtomString(),
                'updated_at' => optional($profile->updated_at)?->toAtomString(),
            ],
        ];
    }

    protected function processProfile(AutoBackupProfile $profile, bool $forceCreate = false): AutoBackupProfile
    {
        /** @var \Pterodactyl\Models\User|null $user */
        $user = $profile->user()->first();
        if (is_null($user)) {
            $profile->fill([
                'is_enabled' => false,
                'last_status' => 'failed',
                'last_error' => 'Profile owner no longer exists.',
            ])->save();

            return $profile;
        }

        try {
            if (!is_null($profile->pending_backup_uuid)) {
                $this->processPendingBackup($profile, $user);

                return $profile->refresh();
            }

            $now = CarbonImmutable::now();
            if (
                !$forceCreate &&
                !is_null($profile->next_run_at) &&
                CarbonImmutable::instance($profile->next_run_at)->isFuture()
            ) {
                $profile->fill([
                    'last_status' => 'waiting',
                    'last_error' => null,
                ])->save();

                return $profile;
            }

            $backupUuid = $this->createBackupForProfile($profile, $user);
            $profile->fill([
                'pending_backup_uuid' => $backupUuid,
                'last_run_at' => $now,
                'last_status' => 'queued',
                'last_error' => null,
                'next_run_at' => $now->addMinutes((int) $profile->interval_minutes),
            ])->save();
        } catch (\Throwable $exception) {
            $profile->fill([
                'last_status' => 'failed',
                'last_error' => $exception->getMessage(),
                'pending_backup_uuid' => null,
                'next_run_at' => CarbonImmutable::now()->addMinutes(max(5, (int) $profile->interval_minutes)),
            ])->save();
        }

        return $profile->refresh();
    }

    protected function processPendingBackup(AutoBackupProfile $profile, User $user): void
    {
        $pendingUuid = (string) $profile->pending_backup_uuid;
        if ($pendingUuid === '') {
            return;
        }

        $state = $this->pendingBackupState($profile, $user, $pendingUuid);

        if ($state['status'] === 'pending') {
            $profile->fill([
                'last_status' => 'waiting',
                'last_error' => null,
            ])->save();

            return;
        }

        if ($state['status'] === 'failed') {
            $profile->fill([
                'pending_backup_uuid' => null,
                'last_status' => 'failed',
                'last_error' => (string) ($state['error'] ?? 'Backup failed before upload.'),
                'next_run_at' => CarbonImmutable::now()->addMinutes(max(5, (int) $profile->interval_minutes)),
            ])->save();

            return;
        }

        $downloadUrl = $this->backupDownloadUrl($profile, $user, $pendingUuid, $state);
        $tempPath = $this->downloadBackupArchive($downloadUrl);

        try {
            $remoteFileName = $this->remoteFileName($profile, $pendingUuid);
            $uploaded = $this->destinationUploader->upload($profile, $tempPath, $remoteFileName);

            $objects = $this->updatedUploadedObjects($profile, $uploaded);

            $profile->fill([
                'pending_backup_uuid' => null,
                'last_backup_uuid' => $pendingUuid,
                'uploaded_objects_json' => $objects,
                'last_status' => 'uploaded',
                'last_error' => null,
                'last_run_at' => CarbonImmutable::now(),
                'next_run_at' => CarbonImmutable::now()->addMinutes((int) $profile->interval_minutes),
            ])->save();

            $this->pruneRemoteBackups($profile);
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * @return array{status:string,error?:string,local_backup?:Backup}
     */
    protected function pendingBackupState(AutoBackupProfile $profile, User $user, string $backupUuid): array
    {
        if ($this->isExternalIdentifier($profile->server_identifier)) {
            return $this->pendingExternalBackupState($profile, $user, $backupUuid);
        }

        $server = $this->localServerFromIdentifier($profile->server_identifier);
        $backup = Backup::query()
            ->where('server_id', $server->id)
            ->where('uuid', $backupUuid)
            ->first();

        if (is_null($backup)) {
            return ['status' => 'failed', 'error' => 'Pending backup was not found on this server.'];
        }

        if (!$backup->is_successful && !is_null($backup->completed_at)) {
            return ['status' => 'failed', 'error' => 'Backup generation failed.'];
        }

        if ($backup->is_successful && !is_null($backup->completed_at)) {
            return ['status' => 'completed', 'local_backup' => $backup];
        }

        return ['status' => 'pending'];
    }

    /**
     * @return array{status:string,error?:string}
     */
    protected function pendingExternalBackupState(AutoBackupProfile $profile, User $user, string $backupUuid): array
    {
        $endpoint = $this->externalBackupEndpoint($profile->server_identifier, "backups/{$backupUuid}");
        $response = $this->externalServerRepository->proxyJson($profile->server_identifier, $user, 'GET', $endpoint);
        $attributes = $this->extractAttributes($response);

        $isSuccessful = (bool) Arr::get($attributes, 'is_successful', false);
        $completedAt = Arr::get($attributes, 'completed_at');

        if ($isSuccessful && !is_null($completedAt)) {
            return ['status' => 'completed'];
        }

        if (!$isSuccessful && !is_null($completedAt)) {
            return ['status' => 'failed', 'error' => 'External panel reports this backup failed.'];
        }

        return ['status' => 'pending'];
    }

    /**
     * @param array<string, mixed> $state
     */
    protected function backupDownloadUrl(
        AutoBackupProfile $profile,
        User $user,
        string $backupUuid,
        array $state
    ): string {
        if ($this->isExternalIdentifier($profile->server_identifier)) {
            $endpoint = $this->externalBackupEndpoint($profile->server_identifier, "backups/{$backupUuid}/download");
            $response = $this->externalServerRepository->proxyJson($profile->server_identifier, $user, 'GET', $endpoint);
            $url = (string) Arr::get($response, 'attributes.url', '');
            if ($url === '') {
                throw new RuntimeException('External backup download URL was not returned.');
            }

            return $url;
        }

        /** @var \Pterodactyl\Models\Backup|null $backup */
        $backup = $state['local_backup'] ?? null;
        if (!$backup instanceof Backup) {
            throw new RuntimeException('Local backup could not be resolved for download.');
        }

        return $this->downloadLinkService->handle($backup, $user);
    }

    protected function createBackupForProfile(AutoBackupProfile $profile, User $user): string
    {
        if ($this->isExternalIdentifier($profile->server_identifier)) {
            $endpoint = $this->externalBackupEndpoint($profile->server_identifier, 'backups');
            $response = $this->externalServerRepository->proxyJson($profile->server_identifier, $user, 'POST', $endpoint, [
                'json' => [
                    'name' => $this->backupName($profile),
                    'ignored' => $profile->ignored_files ?? '',
                    'is_locked' => (bool) $profile->is_locked,
                ],
            ]);

            $backupUuid = (string) Arr::get($response, 'attributes.uuid', Arr::get($response, 'data.attributes.uuid', ''));
            if ($backupUuid === '') {
                throw new RuntimeException('External panel did not return a backup UUID.');
            }

            return $backupUuid;
        }

        $server = $this->localServerFromIdentifier($profile->server_identifier);
        if (!$user->can(Permission::ACTION_BACKUP_CREATE, $server)) {
            throw new RuntimeException('This user no longer has backup.create permission on the server.');
        }

        $ignored = preg_split('/\r\n|\r|\n/', (string) ($profile->ignored_files ?? '')) ?: [];

        $originalLimit = (int) $server->backup_limit;
        $didTemporarilyChangeLimit = false;

        if ($originalLimit <= 0) {
            $nonFailedBackupCount = Backup::query()
                ->where('server_id', $server->id)
                ->where(function ($query) {
                    $query->whereNull('completed_at')
                        ->orWhere('is_successful', true);
                })
                ->count();

            $temporaryLimit = max(1, $nonFailedBackupCount + 1);

            Server::query()
                ->where('id', $server->id)
                ->update(['backup_limit' => $temporaryLimit]);

            $server->backup_limit = $temporaryLimit;
            $didTemporarilyChangeLimit = true;
        }

        try {
            $backup = $this->initiateBackupService
                ->setIgnoredFiles($ignored)
                ->setIsLocked((bool) $profile->is_locked)
                ->handle($server, $this->backupName($profile), true);
        } finally {
            if ($didTemporarilyChangeLimit) {
                Server::query()
                    ->where('id', $server->id)
                    ->update(['backup_limit' => $originalLimit]);

                $server->backup_limit = $originalLimit;
            }
        }

        return $backup->uuid;
    }

    protected function localServerFromIdentifier(string $identifier): Server
    {
        $server = Server::query()->where('uuid', $identifier)->first();
        if (!$server instanceof Server) {
            throw new NotFoundHttpException('The target local server was not found for this auto backup profile.');
        }

        return $server;
    }

    protected function assertServerAccessible(User $user, string $serverIdentifier): void
    {
        if ($this->isExternalIdentifier($serverIdentifier)) {
            // Trigger a lookup to confirm ownership/access to the external server reference.
            $this->externalServerRepository->getServer($serverIdentifier, $user);

            return;
        }

        $server = $this->localServerFromIdentifier($serverIdentifier);
        if (!$user->can(Permission::ACTION_BACKUP_READ, $server)) {
            throw ValidationException::withMessages([
                'server_identifier' => 'You do not have backup access for the selected server.',
            ]);
        }
    }

    protected function getOwnedProfile(User $user, string $identifier, int $profileId): AutoBackupProfile
    {
        $profile = AutoBackupProfile::query()
            ->where('id', $profileId)
            ->where('user_id', $user->id)
            ->where('server_identifier', $identifier)
            ->first();

        if (!$profile instanceof AutoBackupProfile) {
            throw new NotFoundHttpException('Auto backup profile not found.');
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function normalizePayload(array $payload, ?AutoBackupProfile $existing = null): array
    {
        $global = $this->globalSettings->all();
        $destinationType = isset($payload['destination_type'])
            ? strtolower(trim((string) $payload['destination_type']))
            : ($existing?->destination_type ?? (string) Arr::get($global, 'default_destination_type', 'google_drive'));

        if (!in_array($destinationType, ['google_drive', 's3', 'dropbox'], true)) {
            throw ValidationException::withMessages([
                'destination_type' => 'Destination type must be one of: google_drive, s3, dropbox.',
            ]);
        }

        $incomingConfig = Arr::get($payload, 'destination_config');
        if (!is_array($incomingConfig)) {
            $incomingConfig = [];
        }

        $currentConfig = $existing instanceof AutoBackupProfile && $existing->destination_type === $destinationType
            ? $existing->destination_config
            : [];

        $globalConfig = Arr::get($global, "destinations.{$destinationType}", []);
        if (!is_array($globalConfig)) {
            $globalConfig = [];
        }

        $destinationConfig = $this->normalizedDestinationConfig(
            $destinationType,
            $currentConfig,
            $incomingConfig,
            $globalConfig,
            (bool) Arr::get($global, 'allow_user_destination_override', true)
        );

        $intervalMinutes = isset($payload['interval_minutes'])
            ? (int) $payload['interval_minutes']
            : (int) ($existing?->interval_minutes ?? Arr::get($global, 'default_interval_minutes', 360));

        $keepRemote = isset($payload['keep_remote'])
            ? (int) $payload['keep_remote']
            : (int) ($existing?->keep_remote ?? Arr::get($global, 'default_keep_remote', 10));

        if ($intervalMinutes < 5 || $intervalMinutes > 10080) {
            throw ValidationException::withMessages([
                'interval_minutes' => 'Interval must be between 5 and 10080 minutes.',
            ]);
        }

        if ($keepRemote < 1 || $keepRemote > 1000) {
            throw ValidationException::withMessages([
                'keep_remote' => 'Keep remote count must be between 1 and 1000.',
            ]);
        }

        return [
            'name' => isset($payload['name']) ? trim((string) $payload['name']) : ($existing?->name ?? null),
            'destination_type' => $destinationType,
            'destination_config' => $destinationConfig,
            'is_enabled' => isset($payload['is_enabled']) ? (bool) $payload['is_enabled'] : ($existing?->is_enabled ?? true),
            'interval_minutes' => $intervalMinutes,
            'keep_remote' => $keepRemote,
            'is_locked' => isset($payload['is_locked']) ? (bool) $payload['is_locked'] : ($existing?->is_locked ?? false),
            'ignored_files' => array_key_exists('ignored_files', $payload)
                ? trim((string) ($payload['ignored_files'] ?? ''))
                : ($existing?->ignored_files ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $global
     *
     * @return array<string, mixed>
     */
    protected function normalizedDestinationConfig(
        string $type,
        array $current,
        array $incoming,
        array $global,
        bool $allowUserDestinationOverride
    ): array
    {
        $sensitiveKeys = match ($type) {
            'google_drive' => ['client_secret', 'refresh_token', 'service_account_json'],
            's3' => ['secret_access_key'],
            'dropbox' => ['access_token'],
            default => [],
        };
        foreach ($sensitiveKeys as $key) {
            if (!array_key_exists($key, $incoming)) {
                continue;
            }

            $value = $incoming[$key];
            if (is_string($value) && trim($value) === '') {
                unset($incoming[$key]);
            }
        }

        $config = array_merge($current, $incoming);

        $allowed = match ($type) {
            'google_drive' => ['auth_mode', 'client_id', 'client_secret', 'refresh_token', 'service_account_json', 'folder_id'],
            's3' => ['bucket', 'region', 'endpoint', 'path_prefix', 'use_path_style', 'access_key_id', 'secret_access_key'],
            'dropbox' => ['folder_path', 'access_token'],
            default => [],
        };
        $config = Arr::only($config, $allowed);
        $global = Arr::only($global, $allowed);

        $required = match ($type) {
            's3' => ['bucket', 'region', 'access_key_id', 'secret_access_key'],
            'dropbox' => ['access_token'],
            default => [],
        };

        $normalized = [];
        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $normalized[$key] = trim($value);
            } else {
                $normalized[$key] = $value;
            }
        }

        foreach ($global as $key => $value) {
            if (is_string($value)) {
                $global[$key] = trim($value);
            }
        }

        if ($allowUserDestinationOverride) {
            foreach ($global as $key => $value) {
                if (!array_key_exists($key, $normalized) || (is_string($normalized[$key]) && trim($normalized[$key]) === '')) {
                    $normalized[$key] = $value;
                }
            }
        } else {
            foreach ($global as $key => $value) {
                if (!is_bool($value) && trim((string) $value) === '') {
                    continue;
                }

                $normalized[$key] = $value;
            }
        }

        if ($type === 's3') {
            $normalized['use_path_style'] = (bool) ($normalized['use_path_style'] ?? false);
            $normalized['path_prefix'] = trim((string) ($normalized['path_prefix'] ?? ''), '/');
            $normalized['endpoint'] = trim((string) ($normalized['endpoint'] ?? ''));
        }

        if ($type === 'dropbox') {
            $normalized['folder_path'] = trim((string) ($normalized['folder_path'] ?? ''), '/');
        }

        if ($type === 'google_drive') {
            $normalized['auth_mode'] = $this->googleDriveAuthMode($normalized);
            $normalized['folder_id'] = trim((string) ($normalized['folder_id'] ?? ''));
            $normalized['service_account_json'] = trim((string) ($normalized['service_account_json'] ?? ''));

            $hasOauthCredentials = trim((string) ($normalized['client_id'] ?? '')) !== ''
                && trim((string) ($normalized['client_secret'] ?? '')) !== ''
                && trim((string) ($normalized['refresh_token'] ?? '')) !== '';
            if ($normalized['auth_mode'] === 'service_account' && $normalized['service_account_json'] === '' && $hasOauthCredentials) {
                $normalized['auth_mode'] = 'oauth';
            }

            $required = $normalized['auth_mode'] === 'service_account'
                ? ['service_account_json']
                : ['client_id', 'client_secret', 'refresh_token'];
        }

        foreach ($required as $key) {
            $value = $normalized[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw ValidationException::withMessages([
                    "destination_config.$key" => 'This destination field is required.',
                ]);
            }
        }

        return $normalized;
    }

    protected function assertAutoBackupsEnabled(): void
    {
        if ($this->globalSettings->isEnabled()) {
            return;
        }

        throw ValidationException::withMessages([
            'auto_backups' => 'Auto backups are currently disabled by panel administrator.',
        ]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    protected function sanitizedDestinationConfig(string $type, array $config): array
    {
        return match ($type) {
            's3' => [
                'bucket' => (string) ($config['bucket'] ?? ''),
                'region' => (string) ($config['region'] ?? ''),
                'endpoint' => (string) ($config['endpoint'] ?? ''),
                'path_prefix' => (string) ($config['path_prefix'] ?? ''),
                'use_path_style' => (bool) ($config['use_path_style'] ?? false),
                'has_access_key_id' => trim((string) ($config['access_key_id'] ?? '')) !== '',
                'has_secret_access_key' => trim((string) ($config['secret_access_key'] ?? '')) !== '',
            ],
            'dropbox' => [
                'folder_path' => (string) ($config['folder_path'] ?? ''),
                'has_access_token' => trim((string) ($config['access_token'] ?? '')) !== '',
            ],
            'google_drive' => [
                'auth_mode' => $this->googleDriveAuthMode($config),
                'folder_id' => (string) ($config['folder_id'] ?? ''),
                'client_id' => (string) ($config['client_id'] ?? ''),
                'has_client_secret' => trim((string) ($config['client_secret'] ?? '')) !== '',
                'has_refresh_token' => trim((string) ($config['refresh_token'] ?? '')) !== '',
                'has_service_account_json' => trim((string) ($config['service_account_json'] ?? '')) !== '',
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function googleDriveAuthMode(array $config): string
    {
        $mode = strtolower(trim((string) ($config['auth_mode'] ?? '')));
        if (in_array($mode, ['oauth', 'service_account'], true)) {
            return $mode;
        }

        return trim((string) ($config['service_account_json'] ?? '')) !== '' ? 'service_account' : 'oauth';
    }

    protected function normalizeServerIdentifier(string $identifier): string
    {
        $normalized = trim($identifier);
        if ($normalized === '') {
            throw ValidationException::withMessages([
                'server_identifier' => 'Server identifier cannot be empty.',
            ]);
        }

        return $normalized;
    }

    protected function isExternalIdentifier(string $identifier): bool
    {
        return Str::startsWith($identifier, 'external:');
    }

    protected function externalBackupEndpoint(string $identifier, string $suffix): string
    {
        $parts = ExternalServerReference::parseCompositeIdentifier($identifier);

        return sprintf('servers/%s/%s', $parts['server_identifier'], ltrim($suffix, '/'));
    }

    protected function backupName(AutoBackupProfile $profile): string
    {
        $prefix = trim((string) ($profile->name ?? 'Auto Backup'));
        $timestamp = CarbonImmutable::now()->format('Y-m-d H:i:s');

        return sprintf('%s [%s]', $prefix !== '' ? $prefix : 'Auto Backup', $timestamp);
    }

    protected function remoteFileName(AutoBackupProfile $profile, string $backupUuid): string
    {
        $baseName = trim((string) ($profile->name ?? 'auto-backup'));
        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $baseName);
        $safeName = trim((string) $safeName, '-_.');
        $safeName = $safeName !== '' ? $safeName : 'auto-backup';

        return sprintf('%s-%s.tar.gz', $safeName, $backupUuid);
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    protected function extractAttributes(array $response): array
    {
        $attributes = Arr::get($response, 'attributes');
        if (is_array($attributes)) {
            return $attributes;
        }

        $nested = Arr::get($response, 'data.attributes');

        return is_array($nested) ? $nested : [];
    }

    protected function downloadBackupArchive(string $downloadUrl): string
    {
        $directory = storage_path('app/auto-backups');
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create temporary auto backup directory.');
        }

        $path = $directory . DIRECTORY_SEPARATOR . Str::uuid()->toString() . '.tar.gz';
        $client = new Client([
            'timeout' => 900,
            'connect_timeout' => 15,
            'http_errors' => true,
        ]);

        $client->get($downloadUrl, ['sink' => $path]);

        if (!file_exists($path) || filesize($path) === 0) {
            @unlink($path);
            throw new RuntimeException('Downloaded backup archive is empty.');
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $uploadedObject
     *
     * @return array<int, array<string, mixed>>
     */
    protected function updatedUploadedObjects(AutoBackupProfile $profile, array $uploadedObject): array
    {
        $objects = is_array($profile->uploaded_objects_json) ? $profile->uploaded_objects_json : [];
        $objects[] = array_merge($uploadedObject, [
            'uploaded_at' => CarbonImmutable::now()->toAtomString(),
        ]);

        return array_values(array_filter($objects, fn ($item) => is_array($item)));
    }

    protected function pruneRemoteBackups(AutoBackupProfile $profile): void
    {
        $objects = is_array($profile->uploaded_objects_json) ? $profile->uploaded_objects_json : [];
        $keep = max(1, (int) $profile->keep_remote);

        if (count($objects) <= $keep) {
            return;
        }

        $removeCount = count($objects) - $keep;
        $toDelete = array_slice($objects, 0, $removeCount);
        $remaining = array_slice($objects, $removeCount);

        foreach ($toDelete as $object) {
            if (!is_array($object)) {
                continue;
            }

            try {
                $this->destinationUploader->delete($profile, $object);
            } catch (\Throwable) {
                // Intentionally continue; failed remote cleanup should not break upload flow.
            }
        }

        $profile->fill(['uploaded_objects_json' => array_values($remaining)])->save();
    }
}
