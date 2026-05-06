<?php

declare(strict_types=1);

namespace App\Filament\Resources\SiteLogs\Tables;

use App\Models\AppSetting;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SiteLogEntriesTable
{
    /**
     * Filter dropdown options scan the full site_log_entries / pivot table on every page load.
     * The data only changes when ingestion runs (~minute cadence), so a short TTL keeps the
     * dropdowns responsive without showing meaningfully stale options.
     */
    private const FILTER_OPTIONS_TTL = 60;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('logMatches'))
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Date')
                    ->dateTime('Y-m-d H:i:s', AppSetting::timezone())
                    ->sortable()
                    ->tooltip(fn (SiteLogEntry $record): ?string => $record->occurred_at
                        ?->setTimezone(AppSetting::timezone())
                        ->format('Y-m-d H:i:s T')),

                TextColumn::make('server.name')
                    ->label('Server')
                    ->sortable(),

                TextColumn::make('host')
                    ->label('Host')
                    ->searchable()
                    ->limit(32)
                    ->tooltip(fn (SiteLogEntry $record): ?string => $record->host),

                TextColumn::make('remote_addr')
                    ->label('IP')
                    ->fontFamily('mono')
                    ->searchable(),

                TextColumn::make('iso_country')
                    ->label('Country')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('request_uri')
                    ->label('Route')
                    ->limit(40)
                    ->searchable()
                    ->sortable()
                    ->tooltip(fn (SiteLogEntry $record): ?string => $record->request_uri),

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

                TextColumn::make('log_matches')
                    ->label('Logs')
                    ->badge()
                    ->color('gray')
                    ->state(fn (SiteLogEntry $record): array => $record->logMatches->pluck('log_slug')->all())
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('fbclid')
                    ->label('FB')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : 'FB')
                    ->tooltip(fn (SiteLogEntry $record): ?string => $record->fbclid)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('uri')
                    ->label('Path')
                    ->limit(40)
                    ->tooltip(fn (SiteLogEntry $record): ?string => $record->uri)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('user_agent')
                    ->label('UA')
                    ->limit(30)
                    ->tooltip(fn (SiteLogEntry $record): ?string => $record->user_agent)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('browser_name')
                    ->label('Browser')
                    ->formatStateUsing(fn (SiteLogEntry $record): string => trim(($record->browser_name ?? '').' '.($record->browser_version ?? '')) ?: '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('os_name')
                    ->label('OS')
                    ->formatStateUsing(fn (SiteLogEntry $record): string => trim(($record->os_name ?? '').' '.($record->os_version ?? '')) ?: '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('device_type')
                    ->label('Device')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('is_bot')
                    ->label('Bot')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Bot' : '—')
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

                SelectFilter::make('log_slug')
                    ->label('Log')
                    ->searchable()
                    ->options(fn (): array => self::distinctLogSlugOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'logMatches',
                            fn (Builder $q): Builder => $q->where('log_slug', $data['value']),
                        );
                    }),

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

                SelectFilter::make('browser_name')
                    ->label('Browser')
                    ->searchable()
                    ->options(fn (): array => self::distinctOptions('browser_name')),

                SelectFilter::make('os_name')
                    ->label('OS')
                    ->searchable()
                    ->options(fn (): array => self::distinctOptions('os_name')),

                SelectFilter::make('device_type')
                    ->label('Device')
                    ->searchable()
                    ->options(fn (): array => self::distinctOptions('device_type')),

                TernaryFilter::make('is_bot')
                    ->label('Bot')
                    ->placeholder('Any')
                    ->trueLabel('Bots')
                    ->falseLabel('Humans'),

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
            ->persistFiltersInSession()
            ->deferLoading(! app()->runningUnitTests());
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
     * Distinct log slugs from the pivot table as SelectFilter options.
     *
     * @return array<string, string>
     */
    private static function distinctLogSlugOptions(): array
    {
        return Cache::remember(
            'site-log-entries.filters.log-slugs',
            self::FILTER_OPTIONS_TTL,
            function (): array {
                /** @var list<string> $values */
                $values = DB::table('site_log_entry_log_matches')
                    ->distinct()
                    ->orderBy('log_slug')
                    ->pluck('log_slug')
                    ->all();

                return array_combine($values, $values);
            },
        );
    }

    /**
     * Distinct non-null values from site_log_entries.<column> as SelectFilter options.
     *
     * @return array<string, string>
     */
    private static function distinctOptions(string $column): array
    {
        return Cache::remember(
            "site-log-entries.filters.distinct.{$column}",
            self::FILTER_OPTIONS_TTL,
            function () use ($column): array {
                /** @var list<string> $values */
                $values = DB::table('site_log_entries')
                    ->whereNotNull($column)
                    ->where($column, '!=', '')
                    ->distinct()
                    ->orderBy($column)
                    ->pluck($column)
                    ->all();

                return array_combine($values, $values);
            },
        );
    }

    /**
     * site_id => label. Label includes up to two domains so operators can
     * find a site by typing its domain into the filter search.
     *
     * @return array<string, string>
     */
    public static function siteIdOptions(): array
    {
        return Cache::remember(
            'site-log-entries.filters.site-ids',
            self::FILTER_OPTIONS_TTL,
            function (): array {
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
            },
        );
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
