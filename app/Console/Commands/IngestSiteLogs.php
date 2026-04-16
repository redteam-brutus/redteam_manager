<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\SiteLogs\SiteLogIngester;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:ingest-site-logs')]
#[Description('Pulls access.log + gate.log from every registered server and upserts rows into site_log_entries (dedup on request_id).')]
class IngestSiteLogs extends Command
{
    public function handle(SiteLogIngester $ingester): int
    {
        $totalInserted = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach (Server::query()->get() as $server) {
            try {
                $report = $ingester->ingestServer($server);
            } catch (Throwable $e) {
                $this->error("[{$server->name}] fatal: {$e->getMessage()}");
                $totalErrors++;

                continue;
            }

            $totalInserted += $report->rowsInserted;
            $totalUpdated += $report->rowsUpdated;
            $totalSkipped += $report->linesSkipped;
            $totalErrors += count($report->errors);

            $this->line("[{$server->name}] {$report->summary()}");

            foreach ($report->errors as $error) {
                $this->warn("  - {$error}");
            }

            if ($this->output->isVerbose() && $report->skippedSamples !== []) {
                foreach ($report->skippedSamples as $sample) {
                    $this->line("    skip: {$sample}");
                }
            }
        }

        $this->info("Done. inserted={$totalInserted} updated={$totalUpdated} skipped={$totalSkipped} errors={$totalErrors}");

        return self::SUCCESS;
    }
}
