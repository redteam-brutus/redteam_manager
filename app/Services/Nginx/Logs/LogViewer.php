<?php

declare(strict_types=1);

namespace App\Services\Nginx\Logs;

use App\Models\Server;
use App\Services\Nginx\Logs\Dto\LogFile;
use App\Services\Ssh\SshConnectionManager;
use InvalidArgumentException;

class LogViewer
{
    private const LOG_ROOT = '/var/log/nginx';

    private const PATH_PATTERN = '#^/var/log/nginx/[A-Za-z0-9._-]+(\.log|\.log\.[0-9]+)$#';

    public function __construct(private readonly SshConnectionManager $ssh) {}

    /**
     * @return list<LogFile>
     */
    public function list(Server $server): array
    {
        $cmd = 'ls -1 '.self::LOG_ROOT.'/*.log '.self::LOG_ROOT.'/*.log.1 2>/dev/null';
        $result = $server->use_sudo
            ? $this->ssh->runPrivileged($server, $cmd)
            : $this->ssh->run($server, $cmd);

        $paths = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $result->stdout) ?: []),
            fn (string $line): bool => $line !== '' && preg_match(self::PATH_PATTERN, $line) === 1,
        ));

        return array_map(
            fn (string $path): LogFile => new LogFile($path, basename($path)),
            $paths,
        );
    }

    public function tail(Server $server, string $path, int $lines = 500): string
    {
        $this->assertWithinLogRoot($path);

        $lines = max(1, min(2000, $lines));
        $cmd = sprintf('tail -n %d %s', $lines, escapeshellarg($path));

        $result = $server->use_sudo
            ? $this->ssh->runPrivileged($server, $cmd)
            : $this->ssh->run($server, $cmd);

        return $result->stdout;
    }

    private function assertWithinLogRoot(string $path): void
    {
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw new InvalidArgumentException("Log path must be under /var/log/nginx and end in .log or .log.N: {$path}");
        }
    }
}
