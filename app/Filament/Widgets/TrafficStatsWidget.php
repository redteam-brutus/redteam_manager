<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Dashboard\SiteTrafficAggregator;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TrafficStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
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

            Stat::make('Active sites today', (string) $snapshot->activeInjectionSites)
                ->description('With site logging and traffic')
                ->color('gray'),
        ];
    }
}
