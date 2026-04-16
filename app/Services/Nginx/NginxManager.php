<?php

declare(strict_types=1);

namespace App\Services\Nginx;

use App\Models\Server;
use App\Services\Nginx\Dto\NginxFile;
use App\Services\Nginx\Dto\NginxSaveResult;
use App\Services\Nginx\Dto\NginxTestResult;
use App\Services\Ssh\Exceptions\SshCommandException;
use App\Services\Ssh\Exceptions\SshException;
use App\Services\Ssh\SshConnectionManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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

    /**
     * @return array<string, list<string>> map of forge site id => [domain, ...]
     */
    public function listForgeDomains(Server $server): array
    {
        $cmd = "find {$this->nginxRoot}/forge-conf -mindepth 2 -maxdepth 2 -type d -not -name server 2>/dev/null";
        $result = $this->ssh->run($server, $cmd);

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $result->stdout) ?: []),
            fn (string $line): bool => $line !== '',
        ));

        $map = [];

        foreach ($lines as $line) {
            if (preg_match('#forge-conf/([0-9]+)/([^/]+)$#', $line, $m) === 1) {
                $map[$m[1]][] = $m[2];
            }
        }

        foreach ($map as &$domains) {
            sort($domains);
        }

        return $map;
    }

    public function readFile(Server $server, string $path): string
    {
        $this->assertWithinRoot($path);

        return $this->ssh->readFile($server, $path);
    }

    public function fileExists(Server $server, string $path): bool
    {
        $this->assertWithinRoot($path);

        return $this->ssh->fileExists($server, $path);
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
        $stagingPath = '/tmp/redteam-'.Str::random(16).'.tmp';
        $backupPath = $path.'.bak.'.$timestamp;

        try {
            $this->ssh->writeFile($server, $stagingPath, $content);
        } catch (SshException $e) {
            return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
        }

        try {
            $this->copyFile($server, $path, $backupPath);
            $this->installFile($server, $stagingPath, $path);
        } catch (SshException $e) {
            $this->safeRemoveStaging($server, $stagingPath);

            return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
        }

        $validation = $this->validate($server);

        if (! $validation->ok) {
            try {
                $this->installFile($server, $backupPath, $path);
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

    public function writeManagedFile(Server $server, string $path, string $content): NginxSaveResult
    {
        $this->assertManagedPath($path);

        try {
            $existed = $this->ssh->fileExists($server, $path);
        } catch (SshException $e) {
            return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
        }

        $this->sweepLegacyInPlaceBackups($server, $path);

        $timestamp = Carbon::now()->format('YmdHis');
        $stagingPath = '/tmp/redteam-'.Str::random(16).'.tmp';
        $backupPath = $existed ? $this->managedBackupPath($path, $timestamp) : null;

        try {
            $this->ssh->writeFile($server, $stagingPath, $content);
        } catch (SshException $e) {
            return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
        }

        try {
            if ($existed) {
                $this->ensureDirectoryExists($server, $this->managedBackupDir());
                $this->copyFile($server, $path, $backupPath);
            } else {
                $this->ensureDirectoryExists($server, dirname($path));
            }

            $this->installFile($server, $stagingPath, $path);
        } catch (SshException $e) {
            $this->safeRemoveStaging($server, $stagingPath);

            return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
        }

        $validation = $this->validate($server);

        if (! $validation->ok) {
            return $this->rollbackManagedWrite($server, $path, $backupPath, $validation->output);
        }

        $this->pruneManagedBackups($server, $path);

        return new NginxSaveResult(
            ok: true,
            status: 'saved',
            output: $existed ? 'Managed file updated.' : 'Managed file created.',
            backupPath: $backupPath,
        );
    }

    /**
     * Apply a batch of managed-file operations atomically: stage every file, install them all,
     * run nginx -t ONCE, roll every file back on failure. Use for multi-file schema migrations
     * where intermediate single-file states would leave nginx referencing undefined variables.
     *
     * @param  array<string, ?string>  $files  path => new content, or null to delete
     */
    public function applyManagedFilesAtomic(Server $server, array $files): NginxSaveResult
    {
        if ($files === []) {
            return new NginxSaveResult(ok: true, status: 'saved', output: 'Nothing to apply.');
        }

        foreach (array_keys($files) as $path) {
            $this->assertManagedPath($path);
        }

        $ops = [];

        // Phase 1: probe existence + prepare staging/backup paths, sweep legacy junk.
        foreach ($files as $path => $content) {
            try {
                $existed = $this->ssh->fileExists($server, $path);
            } catch (SshException $e) {
                return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
            }

            $this->sweepLegacyInPlaceBackups($server, $path);

            $ops[] = [
                'path' => $path,
                'content' => $content,
                'existed' => $existed,
                'staging' => $content !== null ? '/tmp/redteam-'.Str::random(16).'.tmp' : null,
                'backup' => $existed ? $this->managedBackupPath($path, Carbon::now()->format('YmdHis').'-'.Str::random(4)) : null,
                'installed' => false,
                'removed' => false,
            ];
        }

        if ($this->countDelta($ops) === 0) {
            return new NginxSaveResult(ok: true, status: 'saved', output: 'Managed files already in desired state.');
        }

        // Phase 2: write every new content to staging. Bail early (and clean stagings) on any failure.
        foreach ($ops as $op) {
            if ($op['content'] === null) {
                continue;
            }

            try {
                $this->ssh->writeFile($server, $op['staging'], $op['content']);
            } catch (SshException $e) {
                $this->cleanupStagings($server, $ops);

                return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
            }
        }

        // Phase 3: install (mv) or remove every file, tracking what we did so we can undo it.
        try {
            $this->ensureDirectoryExists($server, $this->managedBackupDir());
        } catch (SshException $e) {
            $this->cleanupStagings($server, $ops);

            return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
        }

        foreach ($ops as &$op) {
            try {
                if ($op['existed']) {
                    $this->copyFile($server, $op['path'], $op['backup']);
                }

                if ($op['content'] !== null) {
                    $this->ensureDirectoryExists($server, dirname($op['path']));
                    $this->installFile($server, $op['staging'], $op['path']);
                    $op['installed'] = true;
                } elseif ($op['existed']) {
                    $this->removeFileStrict($server, $op['path']);
                    $op['removed'] = true;
                }
            } catch (SshException $e) {
                $this->rollbackAtomicBatch($server, $ops);
                $this->cleanupStagings($server, $ops);

                return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
            }
        }
        unset($op);

        // Phase 4: ONE nginx -t for the whole batch.
        $validation = $this->validate($server);

        if (! $validation->ok) {
            $this->rollbackAtomicBatch($server, $ops);
            $this->cleanupStagings($server, $ops);

            return new NginxSaveResult(ok: false, status: 'invalid_config', output: $validation->output);
        }

        foreach ($ops as $op) {
            $this->pruneManagedBackups($server, $op['path']);
        }

        return new NginxSaveResult(
            ok: true,
            status: 'saved',
            output: 'Managed files applied ('.count($ops).' file(s)).',
        );
    }

    public function deleteManagedFile(Server $server, string $path): NginxSaveResult
    {
        $this->assertManagedPath($path);

        try {
            if (! $this->ssh->fileExists($server, $path)) {
                $this->sweepLegacyInPlaceBackups($server, $path);

                return new NginxSaveResult(ok: true, status: 'saved', output: 'Already disabled.');
            }
        } catch (SshException $e) {
            return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
        }

        $this->sweepLegacyInPlaceBackups($server, $path);

        $backupPath = $this->managedBackupPath($path, Carbon::now()->format('YmdHis'));

        try {
            $this->ensureDirectoryExists($server, $this->managedBackupDir());
            $this->copyFile($server, $path, $backupPath);
            $this->removeFileStrict($server, $path);
        } catch (SshException $e) {
            return new NginxSaveResult(ok: false, status: 'io_error', output: $e->getMessage());
        }

        $validation = $this->validate($server);

        if (! $validation->ok) {
            try {
                $this->installFile($server, $backupPath, $path);
            } catch (SshException $e) {
                return new NginxSaveResult(
                    ok: false,
                    status: 'io_error',
                    output: "nginx -t failed and automatic rollback also failed. Restore manually from {$backupPath}: {$e->getMessage()}",
                    backupPath: $backupPath,
                );
            }

            return new NginxSaveResult(ok: false, status: 'invalid_config', output: $validation->output);
        }

        $this->pruneManagedBackups($server, $path);

        return new NginxSaveResult(
            ok: true,
            status: 'saved',
            output: 'Managed file removed.',
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
            $this->safeRemove($server, $oldBackup);
        }
    }

    private function pruneManagedBackups(Server $server, string $path, int $keep = 10): void
    {
        try {
            $backups = $this->ssh->listFiles($server, $this->managedBackupGlob($path));
        } catch (SshException) {
            return;
        }

        if (count($backups) <= $keep) {
            return;
        }

        rsort($backups);

        foreach (array_slice($backups, $keep) as $oldBackup) {
            $this->safeRemove($server, $oldBackup);
        }
    }

    private function managedBackupDir(): string
    {
        return $this->nginxRoot.'/redteam-backups';
    }

    private function managedBackupPath(string $originalPath, string $timestamp): string
    {
        return $this->managedBackupDir().'/'.$this->flattenManagedPath($originalPath).'.bak.'.$timestamp;
    }

    private function managedBackupGlob(string $originalPath): string
    {
        return $this->managedBackupDir().'/'.$this->flattenManagedPath($originalPath).'.bak.*';
    }

    private function flattenManagedPath(string $originalPath): string
    {
        $relative = ltrim(str_replace($this->nginxRoot, '', $originalPath), '/');

        return str_replace('/', '__', $relative);
    }

    /**
     * Sweep any legacy in-place .bak.* files that older builds dropped next to
     * the managed file. Forge's `include forge-conf/<id>/server/*;` picks them
     * up as live config (triggering duplicate directive errors on nginx -t),
     * so we clear them as a best-effort before writing.
     */
    private function sweepLegacyInPlaceBackups(Server $server, string $path): void
    {
        $dir = dirname($path);
        $basename = basename($path);

        $cmd = sprintf(
            'find %s -maxdepth 1 -type f -name %s -delete 2>/dev/null',
            escapeshellarg($dir),
            escapeshellarg($basename.'.bak.*'),
        );

        try {
            $server->use_sudo
                ? $this->ssh->runPrivileged($server, $cmd)
                : $this->ssh->run($server, $cmd);
        } catch (SshException) {
            // best-effort — a leftover backup is only a config issue, not a fatal one
        }
    }

    /**
     * Copy a target file into a same-directory backup, using sudo when the
     * server requires it so the destination inside /etc/nginx is writable.
     */
    private function copyFile(Server $server, string $source, string $destination): void
    {
        if ($server->use_sudo) {
            $cmd = sprintf('cp -p %s %s', escapeshellarg($source), escapeshellarg($destination));
            $this->runSudoOrFail($server, $cmd, "Failed to back up {$source} to {$destination}");

            return;
        }

        $current = $this->ssh->readFile($server, $source);
        $this->ssh->writeFile($server, $destination, $current);
    }

    /**
     * Rename (atomic-on-same-filesystem) staging into the final target path,
     * using sudo when the server requires it. /tmp and /etc are normally on
     * the same filesystem on Linux, so mv performs a rename.
     */
    private function installFile(Server $server, string $source, string $destination): void
    {
        if ($server->use_sudo) {
            $cmd = sprintf('mv %s %s', escapeshellarg($source), escapeshellarg($destination));
            $this->runSudoOrFail($server, $cmd, "Failed to install {$source} at {$destination}");

            return;
        }

        $this->ssh->moveFile($server, $source, $destination);
    }

    private function safeRemove(Server $server, string $path): void
    {
        try {
            $server->use_sudo
                ? $this->ssh->runPrivileged($server, 'rm -f '.escapeshellarg($path))
                : $this->ssh->deleteFile($server, $path);
        } catch (SshException) {
            // best-effort cleanup
        }
    }

    private function safeRemoveStaging(Server $server, string $path): void
    {
        try {
            $this->ssh->deleteFile($server, $path);
        } catch (SshException) {
            // best-effort; staging lives in /tmp and gets cleaned by the OS
        }
    }

    private function runSudoOrFail(Server $server, string $command, string $failureMessage): void
    {
        $result = $this->ssh->runPrivileged($server, $command.' 2>&1');

        if ($result->exitCode !== 0) {
            throw new SshCommandException(
                $failureMessage.': '.($result->stdout !== '' ? trim($result->stdout) : "exit {$result->exitCode}")
            );
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

    /**
     * @param  list<array{path:string,content:?string,existed:bool,staging:?string,backup:?string,installed:bool,removed:bool}>  $ops
     */
    private function countDelta(array $ops): int
    {
        $delta = 0;

        foreach ($ops as $op) {
            if ($op['content'] !== null || $op['existed']) {
                $delta++;
            }
        }

        return $delta;
    }

    /**
     * @param  list<array{path:string,content:?string,existed:bool,staging:?string,backup:?string,installed:bool,removed:bool}>  $ops
     */
    private function cleanupStagings(Server $server, array $ops): void
    {
        foreach ($ops as $op) {
            if ($op['staging'] !== null) {
                $this->safeRemoveStaging($server, $op['staging']);
            }
        }
    }

    /**
     * Undo every completed installation/removal in the batch by restoring from the backup
     * (or deleting what was freshly installed). Best-effort — a backup failure here is a
     * manual-recovery case and we surface it via the saved backupPath in the caller's result.
     *
     * @param  list<array{path:string,content:?string,existed:bool,staging:?string,backup:?string,installed:bool,removed:bool}>  $ops
     */
    private function rollbackAtomicBatch(Server $server, array $ops): void
    {
        foreach (array_reverse($ops) as $op) {
            try {
                if ($op['installed']) {
                    if ($op['backup'] !== null) {
                        $this->installFile($server, $op['backup'], $op['path']);
                    } else {
                        $this->removeFileStrict($server, $op['path']);
                    }
                } elseif ($op['removed'] && $op['backup'] !== null) {
                    $this->installFile($server, $op['backup'], $op['path']);
                }
            } catch (SshException) {
                // best-effort rollback; config-invalid is still the primary signal to the caller
            }
        }
    }

    private function rollbackManagedWrite(Server $server, string $path, ?string $backupPath, string $validationOutput): NginxSaveResult
    {
        try {
            if ($backupPath !== null) {
                $this->installFile($server, $backupPath, $path);
            } else {
                $this->removeFileStrict($server, $path);
            }
        } catch (SshException $e) {
            return new NginxSaveResult(
                ok: false,
                status: 'io_error',
                output: $backupPath !== null
                    ? "nginx -t failed and automatic rollback also failed. Restore manually from {$backupPath}: {$e->getMessage()}"
                    : "nginx -t failed and automatic cleanup also failed. Remove {$path} manually: {$e->getMessage()}",
                backupPath: $backupPath,
            );
        }

        return new NginxSaveResult(
            ok: false,
            status: 'invalid_config',
            output: $validationOutput,
            backupPath: $backupPath,
        );
    }

    private function ensureDirectoryExists(Server $server, string $directory): void
    {
        $cmd = 'mkdir -p '.escapeshellarg($directory).' 2>&1';

        $result = $server->use_sudo
            ? $this->ssh->runPrivileged($server, $cmd)
            : $this->ssh->run($server, $cmd);

        if ($result->exitCode !== 0) {
            throw new SshCommandException("Failed to create directory {$directory}: ".trim($result->stdout));
        }
    }

    private function removeFileStrict(Server $server, string $path): void
    {
        if ($server->use_sudo) {
            $this->runSudoOrFail($server, 'rm -f '.escapeshellarg($path), "Failed to remove {$path}");

            return;
        }

        $this->ssh->deleteFile($server, $path);
    }

    private function assertManagedPath(string $path): void
    {
        $this->assertWithinRoot($path);

        $root = preg_quote($this->nginxRoot, '#');
        $pattern = '#^'.$root.'/(forge-conf/[0-9]+/server|conf\.d)/redteam-[a-z0-9-]+\.conf$#';

        if (preg_match($pattern, $path) !== 1) {
            throw new InvalidArgumentException("Managed path must match {$pattern}");
        }
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
