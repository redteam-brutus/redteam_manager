<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Persisted catalog of {server, site_id} → list<domain>. Populated by the ingest job once per
 * minute via SSH+nginx enumeration. Read paths (dashboard widgets, Filament filter dropdowns)
 * query this table instead of opening fresh SSH sessions on every render.
 *
 * @property int $server_id
 * @property string $site_id
 * @property list<string> $domains
 * @property Carbon|null $synced_at
 */
class ServerSite extends Model
{
    public $incrementing = false;

    protected $fillable = [
        'server_id',
        'site_id',
        'domains',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
