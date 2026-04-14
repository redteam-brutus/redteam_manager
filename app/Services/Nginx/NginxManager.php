<?php

declare(strict_types=1);

namespace App\Services\Nginx;

use App\Models\Server;
use App\Services\Nginx\Dto\NginxFile;
use App\Services\Nginx\Dto\NginxSaveResult;
use App\Services\Nginx\Dto\NginxTestResult;
use App\Services\Ssh\Exceptions\SshException;
use App\Services\Ssh\SshConnectionManager;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class NginxManager
{
    public function __construct(
        private readonly SshConnectionManager $ssh,
        private readonly string $nginxRoot = '/etc/nginx',
    ) {}

    /**
     * @return list<NginxFile>
     */
    public function listFiles(Server $server): array
    {
        $script = implode(' ; ', [
            "(test -f {$this->nginxRoot}/nginx.conf && echo '{$this->nginxRoot}/nginx.conf')",
            "find {$this->nginxRoot}/conf.d -maxdepth 1 -type f -name '*.conf' 2>/dev/null",
            "find {$this->nginxRoot}/forge-conf -maxdepth 2 -type f -name 'site.conf' 2>/dev/null",
            "find {$this->nginxRoot}/forge-conf -type f -path '*/server/*' 2>/dev/null",
        ]);

        $result = $this->ssh->run($server, $script);

        $paths = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $result->stdout) ?: []),
            fn (string $line): bool => $line !== '',
        ));

        return array_map(fn (string $path): NginxFile => $this->classify($path), $paths);
    }

    public function readFile(Server $server, string $path): string
    {
        $this->assertWithinRoot($path);

        return $this->ssh->readFile($server, $path);
    }

    public function validate(Server $server): NginxTestResult
    {
        $result = $this->ssh->runPrivileged($server, 'nginx -t 2>&1');

        return new NginxTestResult(
            ok: $result->exitCode === 0,
            output: $result->stdout,
        );
    }

    public function fileHash(Server $server, string $path): string
    {
        $this->assertWithinRoot($path);

        $escaped = escapeshellarg($path);
        $result = $this->ssh->run($server, "sha256sum {$escaped} | awk '{print \$1}'");

        $hash = trim($result->stdout);

        if ($hash === '' || strlen($hash) !== 64) {
            throw new \RuntimeException("Failed to hash remote file: {$path}");
        }

        return $hash;
    }

    public function saveFile(Server $server, string $path, string $content, string $expectedHash): NginxSaveResult
    {
        $this->assertWithinRoot($path);

        try {
            $currentHash = $this->fileHash($server, $path);
        } catch (SshException $e) {
            return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
        }

        if (! hash_equals($currentHash, $expectedHash)) {
            return new NginxSaveResult(
                ok: false,
                status: 'stale',
                output: 'File changed on disk since it was loaded.',
            );
        }

        $timestamp = Carbon::now()->format('YmdHis');
        $tmpPath = $path.'.redteam.tmp';
        $backupPath = $path.'.bak.'.$timestamp;

        try {
            $this->ssh->writeFile($server, $tmpPath, $content);
        } catch (SshException $e) {
            return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
        }

        try {
            $current = $this->ssh->readFile($server, $path);
            $this->ssh->writeFile($server, $backupPath, $current);
            $this->ssh->moveFile($server, $tmpPath, $path);
        } catch (SshException $e) {
            $this->safeDelete($server, $tmpPath);

            return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
        }

        $validation = $this->validate($server);

        if (! $validation->ok) {
            try {
                $this->ssh->moveFile($server, $backupPath, $path);
            } catch (SshException $e) {
                return new NginxSaveResult(
                    ok: false,
                    status: 'io_error',
                    output: "nginx -t failed and automatic rollback also failed. Restore manually from {$backupPath}: {$e->getMessage()}",
                    backupPath: $backupPath,
                );
            }

            return new NginxSaveResult(
                ok: false,
                status: 'invalid_config',
                output: $validation->output,
            );
        }

        $this->pruneBackups($server, $path);

        return new NginxSaveResult(
            ok: true,
            status: 'saved',
            output: 'Saved with backup.',
            backupPath: $backupPath,
        );
    }

    public function reload(Server $server): NginxTestResult
    {
        $validation = $this->validate($server);

        if (! $validation->ok) {
            return $validation;
        }

        $result = $this->ssh->runPrivileged($server, 'systemctl reload nginx 2>&1');

        return new NginxTestResult(
            ok: $result->exitCode === 0,
            output: $result->stdout !== '' ? $result->stdout : 'Nginx reloaded successfully.',
        );
    }

    private function pruneBackups(Server $server, string $path, int $keep = 10): void
    {
        try {
            $backups = $this->ssh->listFiles($server, $path.'.bak.*');
        } catch (SshException) {
            return;
        }

        if (count($backups) <= $keep) {
            return;
        }

        rsort($backups);

        foreach (array_slice($backups, $keep) as $oldBackup) {
            $this->safeDelete($server, $oldBackup);
        }
    }

    private function safeDelete(Server $server, string $path): void
    {
        try {
            $this->ssh->deleteFile($server, $path);
        } catch (SshException) {
            // swallow; cleanup is best-effort
        }
    }

    private function classify(string $path): NginxFile
    {
        $relative = str_starts_with($path, $this->nginxRoot.'/')
            ? substr($path, strlen($this->nginxRoot) + 1)
            : $path;

        if ($path === $this->nginxRoot.'/nginx.conf') {
            return new NginxFile($path, 'main', $relative);
        }

        if (str_starts_with($relative, 'conf.d/')) {
            return new NginxFile($path, 'conf.d', $relative);
        }

        if (preg_match('#^forge-conf/([^/]+)/server/#', $relative, $m) === 1) {
            return new NginxFile($path, "forge:{$m[1]}/server", $relative);
        }

        if (preg_match('#^forge-conf/([^/]+)/site\.conf$#', $relative, $m) === 1) {
            return new NginxFile($path, "forge:{$m[1]}", $relative);
        }

        return new NginxFile($path, 'other', $relative);
    }

    private function assertWithinRoot(string $path): void
    {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('Path must be absolute.');
        }

        if (str_contains($path, '/../') || str_ends_with($path, '/..')) {
            throw new InvalidArgumentException('Path must not contain parent traversal segments.');
        }

        if ($path !== $this->nginxRoot.'/nginx.conf' && ! str_starts_with($path, $this->nginxRoot.'/')) {
            throw new InvalidArgumentException("Path must be under {$this->nginxRoot}.");
        }
    }
}
