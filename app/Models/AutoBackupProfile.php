<?php

namespace Pterodactyl\Models;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $server_identifier
 * @property string|null $name
 * @property string $destination_type
 * @property string $destination_config_encrypted
 * @property bool $is_enabled
 * @property int $interval_minutes
 * @property int $keep_remote
 * @property bool $is_locked
 * @property string|null $ignored_files
 * @property string|null $pending_backup_uuid
 * @property string|null $last_backup_uuid
 * @property array<int, array<string, mixed>>|null $uploaded_objects_json
 * @property string|null $last_status
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $last_run_at
 * @property \Illuminate\Support\Carbon|null $next_run_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Pterodactyl\Models\User $user
 */
class AutoBackupProfile extends Model
{
    public const RESOURCE_NAME = 'auto_backup_profile';

    protected $table = 'auto_backup_profiles';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $hidden = ['destination_config_encrypted'];

    protected $casts = [
        'user_id' => 'integer',
        'is_enabled' => 'boolean',
        'interval_minutes' => 'integer',
        'keep_remote' => 'integer',
        'is_locked' => 'boolean',
        'uploaded_objects_json' => 'array',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        self::CREATED_AT => 'datetime',
        self::UPDATED_AT => 'datetime',
    ];

    public static array $validationRules = [
        'user_id' => 'required|integer|exists:users,id',
        'server_identifier' => 'required|string|max:191',
        'name' => 'nullable|string|max:191',
        'destination_type' => 'required|string|max:32',
        'destination_config_encrypted' => 'required|string',
        'is_enabled' => 'boolean',
        'interval_minutes' => 'integer|min:5|max:10080',
        'keep_remote' => 'integer|min:1|max:1000',
        'is_locked' => 'boolean',
        'ignored_files' => 'nullable|string',
        'pending_backup_uuid' => 'nullable|string|max:191',
        'last_backup_uuid' => 'nullable|string|max:191',
        'uploaded_objects_json' => 'nullable|array',
        'last_status' => 'nullable|string|max:32',
        'last_error' => 'nullable|string',
        'last_run_at' => 'nullable|date',
        'next_run_at' => 'nullable|date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function setDestinationConfigAttribute(array|string $value): void
    {
        $payload = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
        $this->attributes['destination_config_encrypted'] = Crypt::encryptString($payload ?: '{}');
    }

    public function getDestinationConfigAttribute(): array
    {
        $encrypted = $this->attributes['destination_config_encrypted'] ?? null;
        if (!is_string($encrypted) || $encrypted === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}

