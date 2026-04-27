<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteLogEntryLogMatch extends Model
{
    protected $table = 'site_log_entry_log_matches';

    protected $guarded = [];

    /**
     * @return BelongsTo<SiteLogEntry, $this>
     */
    public function siteLogEntry(): BelongsTo
    {
        return $this->belongsTo(SiteLogEntry::class);
    }
}
