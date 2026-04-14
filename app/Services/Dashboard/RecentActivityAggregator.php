<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Server;
use App\Services\Dashboard\Dto\RecentEdit;
use App\Services\Nginx\NginxManager;
use App\Services\Ssh\Exceptions\SshException;
use App\Services\Ssh\SshConnectionManager;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;

class RecentActivityAggregator
{
    private const CACHE_KEY = 'dashboard.recent-activity';

    private const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private readonly SshConnectionManager $ssh,
        private readonly NginxManager $nginx,
    ) {}

    /**
     * @return list<RecentEdit>
     */
    public function latest(int $limit = 10): array
    {
        return Cache::remember(
            self::CACHE_KEY.".limit-{$limit}",
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->build($limit),
        );
    }

    public function forget(): void
    {
        Cache::flush();
    }

    /**
     * @return list<RecentEdit>
     */
    private function build(int $limit): array
    {
        $edits = [];

        foreach (Server::query()->get() as $server) {
            try {
                $domains = $this->nginx->listForgeDomains($server);
            } catch (SshException) {
                $domains = [];
            }

            try {
                $edits = array_merge($edits, $this->scanServer($server, $domains));
            } catch (SshException) {
                // server unreachable; skip
            }
        }

        usort($edits, fn (RecentEdit $a, RecentEdit $b): int => $b->editedAt->getTimestamp() <=> $a->editedAt->getTimestamp());

        return array_slice($edits, 0, $limit);
    }

    /**
     * @param  array<string, list<string>>  $domains
     * @return list<RecentEdit>
     */
    private function scanServer(Server $server, array $domains): array
    {
        $cmd = "find /etc/nginx/conf.d /etc/nginx/forge-conf -type f \\( -name 'redteam-forge-*.conf' -o -path '*/server/redteam-analytics.conf' \\) -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -40";
        $result = $this->ssh->run($server, $cmd);

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $result->stdout) ?: []),
            fn (string $l): bool => $l !== '',
        ));

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
