<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Dashboard\Dto\RecentEdit;
use App\Services\Dashboard\RecentActivityAggregator;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RecentActivityWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    protected static ?string $heading = 'Recently edited Forge sites';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => collect(app(RecentActivityAggregator::class)->latest(10))
                ->map(fn (RecentEdit $edit): array => [
                    'when' => Carbon::instance(Carbon::parse($edit->editedAt))->diffForHumans(),
                    'server' => $edit->serverName,
                    'site_id' => $edit->siteId,
                    'domains' => $edit->domains,
                    'kind' => $edit->fileKind,
                ]))
            ->columns([
                TextColumn::make('when')->label('When')->size('sm'),
                TextColumn::make('server')->label('Server')->size('sm'),
                TextColumn::make('site_id')->label('Site ID')->fontFamily('mono')->size('sm'),
                TextColumn::make('domains')
                    ->label('Domains')
                    ->badge()
                    ->color('gray')
                    ->size('sm')
                    ->placeholder('—'),
                TextColumn::make('kind')->label('File')->badge()->color(fn (string $state): string => $state === 'http' ? 'primary' : 'gray'),
            ])
            ->paginated(false);
    }
}
