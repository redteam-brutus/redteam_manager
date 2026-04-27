<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SiteLogEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteLogEntry extends Model
{
    /** @use HasFactory<SiteLogEntryFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
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

    /**
     * @return HasMany<SiteLogEntryLogMatch, $this>
     */
    public function logMatches(): HasMany
    {
        return $this->hasMany(SiteLogEntryLogMatch::class);
    }

    /**
     * @param  Builder<SiteLogEntry>  $query
     */
    public function scopeMatchedLog(Builder $query, string $slug): Builder
    {
        return $query->whereHas('logMatches', fn (Builder $q) => $q->where('log_slug', $slug));
    }
}
