<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $external_panel_connection_id
 * @property string $external_server_identifier
 * @property string $name
 * @property string|null $node
 * @property array|null $meta_json
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Pterodactyl\Models\ExternalPanelConnection $connection
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ExternalServerCache newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ExternalServerCache newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ExternalServerCache query()
 *
 * @mixin \Eloquent
 */
class ExternalServerCache extends Model
{
    public const RESOURCE_NAME = 'external_server_cache';

    protected $table = 'external_servers_cache';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'external_panel_connection_id' => 'integer',
        'meta_json' => 'array',
        'last_synced_at' => 'datetime',
        self::CREATED_AT => 'datetime',
        self::UPDATED_AT => 'datetime',
    ];

    public static array $validationRules = [
        'external_panel_connection_id' => 'required|integer|exists:external_panel_connections,id',
        'external_server_identifier' => 'required|string|max:191',
        'name' => 'required|string|max:191',
        'node' => 'sometimes|nullable|string|max:191',
        'meta_json' => 'sometimes|nullable|array',
        'last_synced_at' => 'nullable|date',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ExternalPanelConnection::class, 'external_panel_connection_id');
    }
}
