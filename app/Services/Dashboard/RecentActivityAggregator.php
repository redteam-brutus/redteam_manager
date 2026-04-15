<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Server;
use App\Services\Dashboard\Dto\RecentEdit;
use App\Services\Nginx\DomainCache;
use App\Services\Ssh\Exceptions\SshException;
use App\Services\Ssh\SshConnectionManager;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;

class RecentActivityAggregator
{
    /**
     * Per-server cache prefix. We cache the raw `find` output (list of
     * "<timestamp> <path>" strings) and rebuild the DTOs fresh on every
     * call. Caching typed DTOs directly would leave stale blobs that
     * unserialize into __PHP_Incomplete_Class the moment the DTO's shape
     * evolves — which broke the dashboard after the `domains` field was
     * added to RecentEdit.
     */
    private const SCAN_CACHE_PREFIX = 'dashboard.recent-activity.scan.server-';

    private const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private readonly SshConnectionManager $ssh,
        private readonly DomainCache $domains,
    ) {}

    /**
     * @return list<RecentEdit>
     */
    public function latest(int $limit = 10): array
    {
        $edits = [];

        foreach (Server::query()->get() as $server) {
            $domains = $this->domains->for($server);

            try {
                $lines = $this->rememberScan($server);
            } catch (SshException) {
                continue;
            }

            $edits = array_merge($edits, $this->buildEdits($server, $lines, $domains));
        }

        usort($edits, fn (RecentEdit $a, RecentEdit $b): int => $b->editedAt->getTimestamp() <=> $a->editedAt->getTimestamp());

        return array_slice($edits, 0, $limit);
    }

    public function forget(): void
    {
        foreach (Server::query()->pluck('id') as $id) {
            Cache::forget(self::SCAN_CACHE_PREFIX.$id);
        }
    }

    /**
     * @return list<string>
     */
    private function rememberScan(Server $server): array
    {
        return Cache::remember(
            self::SCAN_CACHE_PREFIX.$server->id,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->scan($server),
        );
    }

    /**
     * @return list<string>
     */
    private function scan(Server $server): array
    {
        $cmd = "find /etc/nginx/conf.d /etc/nginx/forge-conf -type f \\( -name 'redteam-forge-*.conf' -o -path '*/server/redteam-analytics.conf' \\) -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -40";
        $result = $this->ssh->run($server, $cmd);

        return array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $result->stdout) ?: []),
            fn (string $l): bool => $l !== '',
        ));
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, list<string>>  $domains
     * @return list<RecentEdit>
     */
    private function buildEdits(Server $server, array $lines, array $domains): array
    {
        $edits = [];

        foreach ($lines as $line) {
            if (preg_match('/^(?<ts>\d+(?:\.\d+)?)\s+(?<path>\S.*)$/', $line, $m) !== 1) {
                continue;
            }

            $parsed = $this->classify((string) $m['path']);

            if ($parsed === null) {
                continue;
            }

            [$siteId, $kind] = $parsed;
            $siteDomains = $domains[$siteId] ?? [];

            $edits[] = new RecentEdit(
                serverId: (int) $server->id,
                serverName: (string) $server->name,
                siteId: $siteId,
                firstDomain: $siteDomains[0] ?? null,
                domains: $siteDomains,
                fileKind: $kind,
                editedAt: new DateTimeImmutable('@'.(int) floatval($m['ts'])),
            );
        }

        return $edits;
    }

    /**
     * @return array{0:string,1:string}|null [siteId, kind]
     */
    private function classify(string $path): ?array
    {
        if (preg_match('#/conf\.d/redteam-forge-([0-9]+)\.conf$#', $path, $m) === 1) {
            return [$m[1], 'http'];
        }

        if (preg_match('#/forge-conf/([0-9]+)/server/redteam-analytics\.conf$#', $path, $m) === 1) {
            return [$m[1], 'server'];
        }

        return null;
    }
}
