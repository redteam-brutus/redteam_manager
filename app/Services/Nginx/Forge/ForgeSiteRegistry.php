<?php

declare(strict_types=1);

namespace App\Services\Nginx\Forge;

use App\Models\Server;
use App\Services\Nginx\Dto\NginxSaveResult;
use App\Services\Nginx\Forge\Dto\ForgeSite;
use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;
use App\Services\Nginx\NginxManager;
use App\Services\Ssh\Exceptions\SshException;
use InvalidArgumentException;

class ForgeSiteRegistry
{
    private const SERVER_FILENAME = 'redteam-analytics.conf';

    public function __construct(
        private readonly NginxManager $nginx,
        private readonly ForgeSiteSettingsParser $parser,
        private readonly ForgeSiteSettingsRenderer $renderer,
        private readonly string $nginxRoot = '/etc/nginx',
    ) {}

    /**
     * @return list<ForgeSite>
     */
    public function list(Server $server): array
    {
        $files = $this->nginx->listFiles($server);
        $domainMap = $this->nginx->listForgeDomains($server);

        $siteIds = [];
        $managedFlags = [];

        foreach ($files as $file) {
            if (preg_match('/^forge:([0-9]+)(?:\/server)?$/', $file->group, $m) !== 1) {
                continue;
            }

            $siteId = $m[1];
            $siteIds[$siteId] = true;

            if ($file->path === $this->serverManagedPath($siteId)) {
                $managedFlags[$siteId] = true;
            }
        }

        $sites = [];

        foreach (array_keys($siteIds) as $siteId) {
            $siteId = (string) $siteId;

            $sites[] = new ForgeSite(
                siteId: $siteId,
                siteConfPath: $this->nginxRoot."/forge-conf/{$siteId}/site.conf",
                managedPath: $this->serverManagedPath($siteId),
                hasManaged: $managedFlags[$siteId] ?? false,
                settings: new ForgeSiteSettings,
                domains: $domainMap[$siteId] ?? [],
            );
        }

        usort($sites, fn (ForgeSite $a, ForgeSite $b): int => strcmp($a->siteId, $b->siteId));

        return $sites;
    }

    public function find(Server $server, string $siteId): ForgeSite
    {
        $this->assertSiteId($siteId);

        $serverContent = $this->safeReadFile($server, $this->serverManagedPath($siteId));
        $httpContent = $this->safeReadFile($server, $this->httpManagedPath($siteId));

        $hasManaged = $serverContent !== null;
        $combined = ($httpContent ?? '')."\n".($serverContent ?? '');

        $settings = $hasManaged
            ? $this->parser->parse($combined)
            : new ForgeSiteSettings;

        return new ForgeSite(
            siteId: $siteId,
            siteConfPath: $this->nginxRoot."/forge-conf/{$siteId}/site.conf",
            managedPath: $this->serverManagedPath($siteId),
            hasManaged: $hasManaged,
            settings: $settings,
        );
    }

    public function save(Server $server, string $siteId, ForgeSiteSettings $settings): NginxSaveResult
    {
        $this->assertSiteId($siteId);

        if ($settings->isEmpty()) {
            return $this->disable($server, $siteId);
        }

        $rendered = $this->renderer->render($siteId, $settings);
        $httpPath = $this->httpManagedPath($siteId);
        $serverPath = $this->serverManagedPath($siteId);

        $httpResult = $rendered->httpContext !== ''
            ? $this->nginx->writeManagedFile($server, $httpPath, $rendered->httpContext)
            : $this->nginx->deleteManagedFile($server, $httpPath);

        if (! $httpResult->ok) {
            return $httpResult;
        }

        if ($rendered->serverContext === '') {
            return $this->nginx->deleteManagedFile($server, $serverPath);
        }

        $serverResult = $this->nginx->writeManagedFile($server, $serverPath, $rendered->serverContext);

        if (! $serverResult->ok && $rendered->httpContext !== '') {
            $this->nginx->deleteManagedFile($server, $httpPath);
        }

        return $serverResult;
    }

    public function disable(Server $server, string $siteId): NginxSaveResult
    {
        $this->assertSiteId($siteId);

        $serverResult = $this->nginx->deleteManagedFile($server, $this->serverManagedPath($siteId));

        if (! $serverResult->ok) {
            return $serverResult;
        }

        return $this->nginx->deleteManagedFile($server, $this->httpManagedPath($siteId));
    }

    private function safeReadFile(Server $server, string $path): ?string
    {
        try {
            return $this->nginx->readFile($server, $path);
        } catch (SshException) {
            return null;
        }
    }

    private function serverManagedPath(string $siteId): string
    {
        return $this->nginxRoot."/forge-conf/{$siteId}/server/".self::SERVER_FILENAME;
    }

    private function httpManagedPath(string $siteId): string
    {
        return $this->nginxRoot."/conf.d/redteam-forge-{$siteId}.conf";
    }

    private function assertSiteId(string $siteId): void
    {
        if (preg_match('/^[0-9]+$/', $siteId) !== 1) {
            throw new InvalidArgumentException("Invalid Forge site id: {$siteId}");
        }
    }
}
