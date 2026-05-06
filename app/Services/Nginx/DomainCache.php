<?php

declare(strict_types=1);

namespace App\Services\Nginx;

use App\Models\Server;
use App\Models\ServerSite;
use App\Services\Ssh\Exceptions\SshException;
use Illuminate\Support\Facades\DB;

/**
 * Read-through cache for {server, site_id} → domains. Backed by the `server_sites` table so
 * dashboard widgets and Filament filter dropdowns never open an SSH session on render. The
 * IngestSiteLogsJob calls sync($server) once per minute to refresh the table from the live
 * forge-conf layout via NginxManager::listForgeDomains.
 */
class DomainCache
{
    public function __construct(
        private readonly NginxManager $nginx,
    ) {}

    /**
     * @return array<string, list<string>> site_id => domains, sourced entirely from the DB.
     */
    public function for(Server $server): array
    {
        $rows = ServerSite::query()
            ->where('server_id', $server->id)
            ->get(['site_id', 'domains']);

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->site_id] = array_values(array_map('strval', $row->domains ?? []));
        }

        return $map;
    }

    /**
     * Refresh the persisted server_sites rows for one server. Single SSH round-trip per server
     * via NginxManager::listForgeDomains. Removed sites are pruned. Errors are swallowed so a
     * single unreachable server doesn't break the wider ingest cycle.
     */
    public function sync(Server $server): void
    {
        try {
            $live = $this->nginx->listForgeDomains($server);
        } catch (SshException) {
            return;
        }

        $now = now();

        DB::transaction(function () use ($server, $live, $now): void {
            $rows = [];

            foreach ($live as $siteId => $domains) {
                $rows[] = [
                    'server_id' => $server->id,
                    'site_id' => (string) $siteId,
                    'domains' => json_encode(array_values($domains)),
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                ServerSite::query()->upsert(
                    $rows,
                    uniqueBy: ['server_id', 'site_id'],
                    update: ['domains', 'synced_at', 'updated_at'],
                );
            }

            $keepSiteIds = array_map('strval', array_keys($live));

            ServerSite::query()
                ->where('server_id', $server->id)
                ->when($keepSiteIds !== [], fn ($q) => $q->whereNotIn('site_id', $keepSiteIds))
                ->delete();
        });
    }

    /**
     * No-op kept for backward compatibility with existing callers. The DB is now the source of
     * truth; cache invalidation happens implicitly when sync() upserts.
     */
    public function forget(Server $server): void {}

    /**
     * @deprecated Domains live in the DB now; nothing to forget.
     */
    public function forgetAll(): void {}
}
