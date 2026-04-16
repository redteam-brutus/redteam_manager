<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SiteLogEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteLogEntry extends Model
{
    /** @use HasFactory<SiteLogEntryFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'gated' => 'boolean',
            'is_bot' => 'boolean',
            'occurred_at' => 'datetime',
            'imported_at' => 'datetime',
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
