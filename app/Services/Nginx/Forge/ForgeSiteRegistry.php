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

    /**
     * Sentinel used to mark our edit of Forge's site.conf. Makes the edit idempotent
     * and reversible: we match on this marker to restore the original line when
     * site logging is turned off.
     */
    private const ACCESS_LOG_SENTINEL = '# disabled by redteam-manager (site logging enabled)';

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

        $result = $this->nginx->applyManagedFilesAtomic($server, [
            $this->httpManagedPath($siteId) => $rendered->httpContext !== '' ? $rendered->httpContext : null,
            $this->serverManagedPath($siteId) => $rendered->serverContext !== '' ? $rendered->serverContext : null,
        ]);

        if (! $result->ok) {
            return $result;
        }

        return $this->applySiteConfAccessLogToggle($server, $siteId, $settings->siteLoggingEnabled, $result);
    }

    public function disable(Server $server, string $siteId): NginxSaveResult
    {
        $this->assertSiteId($siteId);

        $result = $this->nginx->applyManagedFilesAtomic($server, [
            $this->httpManagedPath($siteId) => null,
            $this->serverManagedPath($siteId) => null,
        ]);

        if (! $result->ok) {
            return $result;
        }

        return $this->applySiteConfAccessLogToggle($server, $siteId, false, $result);
    }

    /**
     * Ensure Forge's site.conf access_log line matches the requested state. Non-fatal on failure:
     * the managed files are already in place, so we annotate the prior success result rather than
     * reporting an error.
     */
    private function applySiteConfAccessLogToggle(
        Server $server,
        string $siteId,
        bool $siteLoggingEnabled,
        NginxSaveResult $priorResult,
    ): NginxSaveResult {
        $result = $this->toggleSiteConfAccessLog($server, $siteId, $siteLoggingEnabled);

        if ($result->ok || $result->status === 'noop') {
            return $priorResult;
        }

        return new NginxSaveResult(
            ok: true,
            status: $priorResult->status,
            output: trim($priorResult->output."\nWarning: access_log off toggle in site.conf: {$result->output}"),
            backupPath: $priorResult->backupPath,
        );
    }

    private function toggleSiteConfAccessLog(Server $server, string $siteId, bool $siteLoggingEnabled): NginxSaveResult
    {
        $path = $this->nginxRoot."/forge-conf/{$siteId}/site.conf";

        try {
            if (! $this->nginx->fileExists($server, $path)) {
                return new NginxSaveResult(ok: true, status: 'noop', output: 'site.conf not present; skipped.');
            }

            $current = $this->nginx->readFile($server, $path);
        } catch (SshException $e) {
            return new NginxSaveResult(ok: false, status: 'io_error', output: "Could not read {$path}: ".$e->getMessage());
        }

        $patched = $siteLoggingEnabled
            ? $this->commentAccessLogOff($current)
            : $this->restoreAccessLogOff($current);

        if ($patched === $current) {
            return new NginxSaveResult(ok: true, status: 'noop', output: 'site.conf already in desired state.');
        }

        try {
            $hash = $this->nginx->fileHash($server, $path);
        } catch (SshException $e) {
            return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
        }

        return $this->nginx->saveFile($server, $path, $patched, $hash);
    }

    private function commentAccessLogOff(string $content): string
    {
        $pattern = '/^(\s*)access_log\s+off\s*;\s*$/m';
        $replacement = '$1# access_log off; '.self::ACCESS_LOG_SENTINEL;

        return preg_replace($pattern, $replacement, $content) ?? $content;
    }

    private function restoreAccessLogOff(string $content): string
    {
        $pattern = '/^(\s*)# access_log off; '.preg_quote(self::ACCESS_LOG_SENTINEL, '/').'\s*$/m';

        return preg_replace($pattern, '$1access_log off;', $content) ?? $content;
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
