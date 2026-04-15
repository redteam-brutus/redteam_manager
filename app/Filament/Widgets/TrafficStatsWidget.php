<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ListensForSiteLogsSync;
use App\Services\Dashboard\SiteTrafficAggregator;
use App\Services\SiteLogs\SiteLogRefresher;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TrafficStatsWidget extends StatsOverviewWidget
{
    use ListensForSiteLogsSync;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $refresher = app(SiteLogRefresher::class);
        $state = $refresher->ensureFresh();
        $report = $refresher->lastReport();
        $snapshot = app(SiteTrafficAggregator::class)->snapshot();

        return [
            Stat::make('Visits today', number_format($snapshot->totalVisits))
                ->description('Across '.$snapshot->activeInjectionSites.' active site'.($snapshot->activeInjectionSites === 1 ? '' : 's'))
                ->color('primary'),

            Stat::make('Gate hits today', number_format($snapshot->totalGateHits))
                ->description('Matched all enabled gates')
                ->color('warning'),

            Stat::make('FBCLID hits today', number_format($snapshot->totalFbclidHits))
                ->description('Attributable to Facebook')
                ->color('success'),

            Stat::make('Active sites today', number_format($snapshot->activeInjectionSites))
                ->description('With site logging and traffic')
                ->color('gray'),

            Stat::make('Sync', $this->syncLabel($state, $refresher->staleness()))
                ->description($this->syncHint($state, $report))
                ->color($this->syncColor($state, $report)),
        ];
    }

    private function syncLabel(string $state, ?int $ageSeconds): string
    {
        return match ($state) {
            'fresh' => 'Up to date',
            'running' => 'Running…',
            'triggered' => 'Refreshing…',
            'never' => 'First sync…',
            default => $ageSeconds === null ? '—' : $ageSeconds.'s ago',
        };
    }

    /**
     * @param  array{inserted:int,updated:int,errors:list<string>}|null  $report
     */
    private function syncHint(string $state, ?array $report): string
    {
        if ($report !== null) {
            $errors = count($report['errors'] ?? []);

            if ($errors > 0) {
                return 'Last run: '.$errors.' error'.($errors === 1 ? '' : 's').' — click Sync now';
            }

            return sprintf(
                'Last: +%d inserted, ±%d updated',
                (int) ($report['inserted'] ?? 0),
                (int) ($report['updated'] ?? 0),
            );
        }

        return match ($state) {
            'fresh' => 'Auto-refreshes every 30 s',
            'running' => 'Another request is ingesting',
            'triggered' => 'Ingest running after response',
            'never' => 'Pulling first batch from servers',
            default => '',
        };
    }

    /**
     * @param  array{errors:list<string>}|null  $report
     */
    private function syncColor(string $state, ?array $report): string
    {
        if ($report !== null && ($report['errors'] ?? []) !== []) {
            return 'danger';
        }

        return match ($state) {
            'fresh' => 'gray',
            'running', 'triggered', 'never' => 'warning',
            default => 'gray',
        };
    }
}
