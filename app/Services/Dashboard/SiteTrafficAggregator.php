<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Server;
use App\Models\SiteLogEntry;
use App\Services\Dashboard\Dto\SiteTrafficRow;
use App\Services\Dashboard\Dto\SiteTrafficSnapshot;
use App\Services\Nginx\DomainCache;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SiteTrafficAggregator
{
    public const SNAPSHOT_CACHE_KEY = 'dashboard.site-traffic.snapshot';

    private const SNAPSHOT_TTL_SECONDS = 30;

    public function __construct(
        private readonly DomainCache $domains,
    ) {}

    public function forgetDomainCache(): void
    {
        $this->domains->forgetAll();
    }

    public function forgetSnapshot(): void
    {
        Cache::forget(self::SNAPSHOT_CACHE_KEY);
    }

    public function snapshot(): SiteTrafficSnapshot
    {
        $payload = Cache::remember(
            self::SNAPSHOT_CACHE_KEY,
            self::SNAPSHOT_TTL_SECONDS,
            fn (): array => $this->computeSnapshotPayload(),
        );

        // Defensive: when the cache holds a value from an older code version (e.g. a serialized
        // DTO whose class no longer matches), unserialize returns __PHP_Incomplete_Class instead of
        // an array. Detect, drop, recompute. Beats forcing an operator to run cache:clear.
        if (! is_array($payload) || ! isset($payload['totalVisits'])) {
            Cache::forget(self::SNAPSHOT_CACHE_KEY);
            $payload = $this->computeSnapshotPayload();
            Cache::put(self::SNAPSHOT_CACHE_KEY, $payload, self::SNAPSHOT_TTL_SECONDS);
        }

        return $this->hydrateSnapshot($payload);
    }

    /**
     * @return array{
     *     totalVisits: int,
     *     totalGateHits: int,
     *     totalFbclidHits: int,
     *     activeInjectionSites: int,
     *     rows: list<array{
     *         serverId: int,
     *         serverName: string,
     *         siteId: string,
     *         firstDomain: string|null,
     *         domains: list<string>,
     *         visits: int,
     *         gateHits: int,
     *         fbclidHits: int,
     *     }>,
     *     generatedAt: int,
     * }
     */
    private function computeSnapshotPayload(): array
    {
        $since = now()->startOfDay();

        $query = SiteLogEntry::query()->where('occurred_at', '>=', $since);

        $totalVisits = (int) (clone $query)->count();
        $totalGateHits = (int) (clone $query)
            ->whereHas('logMatches', fn ($q) => $q->where('log_slug', 'gate'))
            ->count();
        $totalFbclidHits = (int) (clone $query)->whereNotNull('fbclid')->count();

        $perSite = DB::table('site_log_entries as sle')
            ->leftJoin('site_log_entry_log_matches as m', function ($join): void {
                $join->on('m.site_log_entry_id', '=', 'sle.id')
                    ->where('m.log_slug', '=', 'gate');
            })
            ->select('sle.server_id', 'sle.site_id')
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw('COUNT(m.id) AS gate_hits')
            ->selectRaw('COUNT(*) FILTER (WHERE sle.fbclid IS NOT NULL) AS fbclid_hits')
            ->where('sle.occurred_at', '>=', $since)
            ->groupBy('sle.server_id', 'sle.site_id')
            ->get()
            ->keyBy(fn ($r): string => $r->server_id.':'.$r->site_id);

        $servers = Server::query()->get()->keyBy('id');
        $domainMap = [];

        foreach ($servers as $server) {
            $domainMap[$server->id] = $this->domains->for($server);
        }

        $rows = [];

        foreach ($perSite as $row) {
            $serverId = (int) $row->server_id;
            $siteId = (string) $row->site_id;
            $server = $servers->get($serverId);

            if ($server === null) {
                continue;
            }

            $domains = $domainMap[$serverId][$siteId] ?? [];

            $rows[] = [
                'serverId' => $serverId,
                'serverName' => (string) $server->name,
                'siteId' => $siteId,
                'firstDomain' => $domains[0] ?? null,
                'domains' => $domains,
                'visits' => (int) $row->visits,
                'gateHits' => (int) $row->gate_hits,
                'fbclidHits' => (int) $row->fbclid_hits,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['visits'] <=> $a['visits']);

        return [
            'totalVisits' => $totalVisits,
            'totalGateHits' => $totalGateHits,
            'totalFbclidHits' => $totalFbclidHits,
            'activeInjectionSites' => count($rows),
            'rows' => $rows,
            'generatedAt' => now()->timestamp,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hydrateSnapshot(array $payload): SiteTrafficSnapshot
    {
        $rows = array_map(
            fn (array $row): SiteTrafficRow => new SiteTrafficRow(
                serverId: (int) $row['serverId'],
                serverName: (string) $row['serverName'],
                siteId: (string) $row['siteId'],
                firstDomain: $row['firstDomain'] !== null ? (string) $row['firstDomain'] : null,
                domains: array_values(array_map('strval', $row['domains'] ?? [])),
                visits: (int) $row['visits'],
                gateHits: (int) $row['gateHits'],
                fbclidHits: (int) $row['fbclidHits'],
            ),
            $payload['rows'] ?? [],
        );

        return new SiteTrafficSnapshot(
            totalVisits: (int) ($payload['totalVisits'] ?? 0),
            totalGateHits: (int) ($payload['totalGateHits'] ?? 0),
            totalFbclidHits: (int) ($payload['totalFbclidHits'] ?? 0),
            activeInjectionSites: (int) ($payload['activeInjectionSites'] ?? count($rows)),
            rows: $rows,
            generatedAt: (new DateTimeImmutable)->setTimestamp((int) ($payload['generatedAt'] ?? now()->timestamp)),
        );
    }
}
