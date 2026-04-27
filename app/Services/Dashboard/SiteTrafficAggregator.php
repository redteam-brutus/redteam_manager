<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Server;
use App\Models\SiteLogEntry;
use App\Services\Dashboard\Dto\SiteTrafficRow;
use App\Services\Dashboard\Dto\SiteTrafficSnapshot;
use App\Services\Nginx\DomainCache;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class SiteTrafficAggregator
{
    public function __construct(
        private readonly DomainCache $domains,
    ) {}

    public function forgetDomainCache(): void
    {
        $this->domains->forgetAll();
    }

    public function snapshot(): SiteTrafficSnapshot
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

            $rows[] = new SiteTrafficRow(
                serverId: $serverId,
                serverName: $server->name,
                siteId: $siteId,
                firstDomain: $domains[0] ?? null,
                domains: $domains,
                visits: (int) $row->visits,
                gateHits: (int) $row->gate_hits,
                fbclidHits: (int) $row->fbclid_hits,
            );
        }

        usort($rows, fn (SiteTrafficRow $a, SiteTrafficRow $b): int => $b->visits <=> $a->visits);

        $activeSitesToday = count($rows);

        return new SiteTrafficSnapshot(
            totalVisits: $totalVisits,
            totalGateHits: $totalGateHits,
            totalFbclidHits: $totalFbclidHits,
            activeInjectionSites: $activeSitesToday,
            rows: $rows,
            generatedAt: new DateTimeImmutable,
        );
    }
}
