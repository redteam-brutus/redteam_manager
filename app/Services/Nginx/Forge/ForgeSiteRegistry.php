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
    private const MANAGED_FILENAME = 'redteam-analytics.conf';

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

            if ($file->path === $this->managedPath($siteId)) {
                $managedFlags[$siteId] = true;
            }
        }

        $sites = [];

        foreach (array_keys($siteIds) as $siteId) {
            $siteId = (string) $siteId;

            $sites[] = new ForgeSite(
                siteId: $siteId,
                siteConfPath: $this->nginxRoot."/forge-conf/{$siteId}/site.conf",
                managedPath: $this->managedPath($siteId),
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

        return $this->buildSite($server, $siteId, loadContent: true);
    }

    public function save(Server $server, string $siteId, ForgeSiteSettings $settings): NginxSaveResult
    {
        $this->assertSiteId($siteId);

        if ($settings->isEmpty()) {
            return $this->disable($server, $siteId);
        }

        $content = $this->renderer->render($siteId, $settings);

        return $this->nginx->writeManagedFile($server, $this->managedPath($siteId), $content);
    }

    public function disable(Server $server, string $siteId): NginxSaveResult
    {
        $this->assertSiteId($siteId);

        return $this->nginx->deleteManagedFile($server, $this->managedPath($siteId));
    }

    private function buildSite(Server $server, string $siteId, bool $loadContent): ForgeSite
    {
        $managedPath = $this->managedPath($siteId);
        $content = null;

        try {
            $content = $this->nginx->readFile($server, $managedPath);
        } catch (SshException) {
            $content = null;
        }

        $settings = $loadContent && $content !== null
            ? $this->parser->parse($content)
            : new ForgeSiteSettings;

        return new ForgeSite(
            siteId: $siteId,
            siteConfPath: $this->nginxRoot."/forge-conf/{$siteId}/site.conf",
            managedPath: $managedPath,
            hasManaged: $content !== null,
            settings: $settings,
        );
    }

    private function managedPath(string $siteId): string
    {
        return $this->nginxRoot."/forge-conf/{$siteId}/server/".self::MANAGED_FILENAME;
    }

    private function assertSiteId(string $siteId): void
    {
        if (preg_match('/^[0-9]+$/', $siteId) !== 1) {
            throw new InvalidArgumentException("Invalid Forge site id: {$siteId}");
        }
    }
}
