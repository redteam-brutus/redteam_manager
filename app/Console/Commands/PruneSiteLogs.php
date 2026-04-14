<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SiteLogEntry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prune-site-logs {--days=30 : Delete rows whose occurred_at is older than this many days}')]
#[Description('Drops site_log_entries older than the retention window (default 30 days).')]
class PruneSiteLogs extends Command
{
    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be a positive integer.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = SiteLogEntry::query()->where('occurred_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} row(s) older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
