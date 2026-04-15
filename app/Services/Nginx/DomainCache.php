<?php

declare(strict_types=1);

namespace App\Services\Nginx;

use App\Models\Server;
use App\Services\Ssh\Exceptions\SshException;
use Illuminate\Support\Facades\Cache;

class DomainCache
{
    private const TTL_SECONDS = 60;

    public function __construct(
        private readonly NginxManager $nginx,
    ) {}

    /**
     * @return array<string, list<string>> site_id => domains
     */
    public function for(Server $server): array
    {
        return Cache::remember(
            $this->key($server),
            self::TTL_SECONDS,
            function () use ($server): array {
                try {
                    return $this->nginx->listForgeDomains($server);
                } catch (SshException) {
                    return [];
                }
            },
        );
    }

    public function forget(Server $server): void
    {
        Cache::forget($this->key($server));
    }

    public function forgetAll(): void
    {
        foreach (Server::query()->pluck('id') as $id) {
            Cache::forget("domains.server-{$id}");
        }
    }

    private function key(Server $server): string
    {
        return "domains.server-{$server->id}";
    }
}
