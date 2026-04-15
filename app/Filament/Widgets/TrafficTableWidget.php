<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ListensForSiteLogsSync;
use App\Services\Dashboard\Dto\SiteTrafficRow;
use App\Services\Dashboard\SiteTrafficAggregator;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

class TrafficTableWidget extends TableWidget
{
    use ListensForSiteLogsSync;

    protected static bool $isLazy = true;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    protected static ?string $heading = 'Today by site';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => collect(app(SiteTrafficAggregator::class)->snapshot()->rows)
                ->map(fn (SiteTrafficRow $row): array => [
                    'server' => $row->serverName,
                    'site_id' => $row->siteId,
                    'domains' => $row->domains,
                    'visits' => $row->visits,
                    'gate_hits' => $row->gateHits,
                    'fbclid_hits' => $row->fbclidHits,
                ]))
            ->columns([
                TextColumn::make('server')->label('Server')->size('sm'),
                TextColumn::make('site_id')->label('Site ID')->fontFamily('mono')->size('sm'),
                TextColumn::make('domains')
                    ->label('Domains')
                    ->badge()
                    ->color('gray')
                    ->size('sm')
                    ->placeholder('—'),
                TextColumn::make('visits')->label('Visits')->numeric()->sortable()->alignEnd(),
                TextColumn::make('gate_hits')->label('Gated')->numeric()->sortable()->alignEnd(),
                TextColumn::make('fbclid_hits')->label('FBCLID')->numeric()->sortable()->alignEnd(),
            ])
            ->defaultSort('visits', 'desc')
            ->paginated(false);
    }
}
