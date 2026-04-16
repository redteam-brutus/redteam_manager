<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SiteLogEntry;
use App\Services\SiteLogs\UserAgentParser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('site-logs:backfill-user-agents {--chunk=500}')]
#[Description('Populate browser / OS / device / is_bot for existing site_log_entries rows.')]
class BackfillSiteLogUserAgents extends Command
{
    public function handle(UserAgentParser $parser): int
    {
        $chunk = (int) $this->option('chunk');

        if ($chunk < 1) {
            $this->error('--chunk must be >= 1');

            return self::INVALID;
        }

        $query = SiteLogEntry::query()
            ->whereNull('browser_name')
            ->whereNotNull('user_agent');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $updated = 0;

        $query->orderBy('id')->chunkById($chunk, function ($rows) use ($parser, $bar, &$updated): void {
            foreach ($rows as $row) {
                $fields = $parser->parse($row->user_agent);

                $row->forceFill($fields)->saveQuietly();
                $updated++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Backfilled {$updated} rows.");

        return self::SUCCESS;
    }
}
