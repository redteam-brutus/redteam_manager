<?php

declare(strict_types=1);

namespace App\Filament\Resources\SiteLogs\Tables;

use App\Models\Server;
use App\Models\SiteLogEntry;
use App\Services\Nginx\DomainCache;
use App\Support\IsoCountries;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SiteLogEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('When')
                    ->since()
                    ->sortable()
                    ->tooltip(fn (SiteLogEntry $record): ?string => $record->occurred_at?->toDateTimeString()),

                TextColumn::make('server.name')
                    ->label('Server')
                    ->sortable(),

                TextColumn::make('site_id')
                    ->label('Site')
                    ->fontFamily('mono')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('domains')
                    ->label('Domains')
                    ->badge()
                    ->color('gray')
                    ->state(fn (SiteLogEntry $record): array => self::domainsFor($record))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('host')
                    ->label('Host')
                    ->searchable()
                    ->limit(32)
                    ->tooltip(fn (SiteLogEntry $record): ?string => $record->host)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('remote_addr')
                    ->label('IP')
                    ->fontFamily('mono')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('iso_country')
                    ->label('Country')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('gated')
                    ->label('Gated')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Gated' : '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('fbclid')
                    ->label('FB')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : 'FB')
                    ->tooltip(fn (SiteLogEntry $record): ?string => $record->fbclid)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('uri')
                    ->label('URI')
                    ->limit(40)
                    ->tooltip(fn (SiteLogEntry $record): ?string => $record->uri)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('user_agent')
                    ->label('UA')
                    ->limit(30)
                    ->tooltip(fn (SiteLogEntry $record): ?string => $record->user_agent)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('request_id')
                    ->label('Request')
                    ->fontFamily('mono')
                    ->limit(12)
                    ->tooltip(fn (SiteLogEntry $record): string => $record->request_id)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('server_id')
                    ->label('Server')
                    ->searchable()
                    ->options(fn (): array => Server::query()->orderBy('name')->pluck('name', 'id')->all()),

                SelectFilter::make('site_id')
                    ->label('Site')
                    ->searchable()
                    ->options(fn (): array => self::siteIdOptions()),

                TernaryFilter::make('gated')
                    ->placeholder('Any')
                    ->trueLabel('Gated')
                    ->falseLabel('Not gated'),

                TernaryFilter::make('fbclid')
                    ->label('FBCLID')
                    ->placeholder('Any')
                    ->trueLabel('Has fbclid')
                    ->falseLabel('No fbclid')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('fbclid'),
                        false: fn (Builder $query): Builder => $query->whereNull('fbclid'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                SelectFilter::make('iso_country')
                    ->label('Country')
                    ->searchable()
                    ->options(fn (): array => IsoCountries::options()),

                Filter::make('host')
                    ->schema([
                        TextInput::make('host')->placeholder('contains…'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['host'] ?? null), fn (Builder $q) => $q->whereRaw('LOWER(host) LIKE ?', ['%'.strtolower((string) $data['host']).'%'])))
                    ->indicateUsing(function (array $data): ?Indicator {
                        if (blank($data['host'] ?? null)) {
                            return null;
                        }

                        return Indicator::make("Host contains “{$data['host']}”")->removeField('host');
                    }),

                Filter::make('occurred_at')
                    ->schema([
                        DatePicker::make('from')->native(false),
                        DatePicker::make('until')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('occurred_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, string $d) => $q->whereDate('occurred_at', '<=', $d)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('From '.CarbonImmutable::parse($data['from'])->toFormattedDateString())
                                ->removeField('from');
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = Indicator::make('Until '.CarbonImmutable::parse($data['until'])->toFormattedDateString())
                                ->removeField('until');
                        }

                        return $indicators;
                    }),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->persistSortInSession()
            ->persistFiltersInSession();
    }

    /**
     * @return list<string>
     */
    private static function domainsFor(SiteLogEntry $record): array
    {
        $server = $record->server;

        if ($server === null) {
            return [];
        }

        return app(DomainCache::class)->for($server)[$record->site_id] ?? [];
    }

    /**
     * site_id => label. Label includes up to two domains so operators can
     * find a site by typing its domain into the filter search.
     *
     * @return array<string, string>
     */
    public static function siteIdOptions(): array
    {
        $rows = DB::table('site_log_entries')
            ->select('server_id', 'site_id')
            ->distinct()
            ->orderBy('site_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $servers = Server::query()
            ->whereIn('id', $rows->pluck('server_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $cache = app(DomainCache::class);
        $options = [];

        foreach ($rows as $row) {
            $siteId = (string) $row->site_id;
            $server = $servers->get($row->server_id);
            $domains = $server !== null ? ($cache->for($server)[$siteId] ?? []) : [];
            $options[$siteId] = self::formatSiteLabel($siteId, $domains);
        }

        return $options;
    }

    /**
     * @param  list<string>  $domains
     */
    private static function formatSiteLabel(string $siteId, array $domains): string
    {
        if ($domains === []) {
            return $siteId;
        }

        $preview = array_slice($domains, 0, 2);
        $label = $siteId.' — '.implode(', ', $preview);
        $more = count($domains) - count($preview);

        if ($more > 0) {
            $label .= ' (+'.$more.')';
        }

        return $label;
    }
}
