<?php

namespace Pterodactyl\Models;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $name
 * @property string $panel_url
 * @property string|null $websocket_origin
 * @property string $api_key_encrypted
 * @property bool $default_connection
 * @property \Illuminate\Support\Carbon|null $last_verified_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Pterodactyl\Models\User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ExternalPanelConnection newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ExternalPanelConnection newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ExternalPanelConnection query()
 *
 * @mixin \Eloquent
 */
class ExternalPanelConnection extends Model
{
    public const RESOURCE_NAME = 'external_panel_connection';

    protected $table = 'external_panel_connections';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'user_id' => 'integer',
        'default_connection' => 'boolean',
        'last_verified_at' => 'datetime',
        self::CREATED_AT => 'datetime',
        self::UPDATED_AT => 'datetime',
    ];

    public static array $validationRules = [
        'user_id' => 'required|integer|exists:users,id',
        'name' => 'sometimes|nullable|string|max:191',
        'panel_url' => 'required|string|max:191',
        'websocket_origin' => 'sometimes|nullable|string|max:191',
        'api_key_encrypted' => 'required|string',
        'default_connection' => 'boolean',
        'last_verified_at' => 'nullable|date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cachedServers(): HasMany
    {
        return $this->hasMany(ExternalServerCache::class, 'external_panel_connection_id');
    }

    public function setApiKeyEncryptedAttribute(string $value): void
    {
        $this->attributes['api_key_encrypted'] = Crypt::encryptString($value);
    }

    public function getApiKeyAttribute(): string
    {
        return Crypt::decryptString($this->attributes['api_key_encrypted']);
    }
}
