<?php

declare(strict_types=1);

namespace App\Services\SiteLogs;

use App\Models\Server;
use App\Services\SiteLogs\Dto\IngestReport;
use App\Services\Ssh\Exceptions\SshException;
use App\Services\Ssh\SshConnectionManager;
use Illuminate\Support\Facades\DB;

class SiteLogIngester
{
    /**
     * Cap per-file ingest to this many of the most recent lines. Protects us from unbounded
     * memory growth on very active sites; on a minute cadence this leaves plenty of headroom
     * above typical request rates.
     */
    private const TAIL_LINES = 50000;

    private const CHUNK_SIZE = 500;

    private const MAX_SKIPPED_SAMPLES = 5;

    private const SKIPPED_SAMPLE_LENGTH = 180;

    private const QUICK_REQ_ID_PATTERN = '/\|\s*ReqID:\s+(\S+)\s*\|/';

    private const KNOWN_WINDOW_HOURS = 24;

    private const ACCESS_COLUMNS = [
        'occurred_at', 'host', 'remote_addr', 'uri', 'request_uri', 'fbclid', 'user_agent',
        'iso_country', 'prefetch', 'turbolink', 'sec_ch_ua', 'sec_ch_ua_platform',
        'sec_ch_ua_mobile', 'raw_line', 'imported_at',
    ];

    public function __construct(
        private readonly SshConnectionManager $ssh,
        private readonly VerboseLogLineParser $parser,
        private readonly string $nginxLogDir = '/var/log/nginx',
    ) {}

    public function ingestServer(Server $server): IngestReport
    {
        $start = microtime(true);
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $skippedSamples = [];

        try {
            $files = $this->enumerateLogFiles($server);
        } catch (SshException $e) {
            return new IngestReport(0, 0, 0, $this->elapsed($start), ["enumerate: {$e->getMessage()}"]);
        }

        foreach ($files['access'] as $siteId => $path) {
            $siteId = (string) $siteId;

            try {
                [$i, $s, $samples] = $this->ingestFile($server, $siteId, $path, gated: false);
                $inserted += $i;
                $skipped += $s;
                $skippedSamples = $this->mergeSamples($skippedSamples, $samples);
            } catch (SshException $e) {
                $errors[] = "access ({$siteId}): {$e->getMessage()}";
            }
        }

        foreach ($files['gate'] as $siteId => $path) {
            $siteId = (string) $siteId;

            try {
                [$u, $s, $samples] = $this->ingestFile($server, $siteId, $path, gated: true);
                $updated += $u;
                $skipped += $s;
                $skippedSamples = $this->mergeSamples($skippedSamples, $samples);
            } catch (SshException $e) {
                $errors[] = "gate ({$siteId}): {$e->getMessage()}";
            }
        }

        return new IngestReport($inserted, $updated, $skipped, $this->elapsed($start), $errors, $skippedSamples);
    }

    /**
     * @param  list<string>  $accumulated
     * @param  list<string>  $new
     * @return list<string>
     */
    private function mergeSamples(array $accumulated, array $new): array
    {
        foreach ($new as $sample) {
            if (count($accumulated) >= self::MAX_SKIPPED_SAMPLES) {
                break;
            }

            $accumulated[] = $sample;
        }

        return $accumulated;
    }

    /**
     * @return array{access: array<string, string>, gate: array<string, string>}
     */
    private function enumerateLogFiles(Server $server): array
    {
        $cmd = sprintf(
            'find %s -maxdepth 1 -type f \\( -name %s -o -name %s \\) 2>/dev/null',
            escapeshellarg($this->nginxLogDir),
            escapeshellarg('site-*-access.log'),
            escapeshellarg('site-*-gate.log'),
        );

        $result = $this->ssh->run($server, $cmd);
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $result->stdout) ?: []),
            fn (string $l): bool => $l !== '',
        ));

        $access = [];
        $gate = [];

        foreach ($lines as $path) {
            if (preg_match('#/site-([^/]+)-(access|gate)\.log$#', $path, $m) !== 1) {
                continue;
            }

            if ($m[2] === 'access') {
                $access[$m[1]] = $path;
            } else {
                $gate[$m[1]] = $path;
            }
        }

        return ['access' => $access, 'gate' => $gate];
    }

    /**
     * @return array{0:int,1:int,2:list<string>} [rowsAffected, linesSkipped, skippedSamples]
     */
    private function ingestFile(Server $server, string $siteId, string $path, bool $gated): array
    {
        $cmd = sprintf('tail -n %d %s 2>/dev/null', self::TAIL_LINES, escapeshellarg($path));
        $result = $this->ssh->run($server, $cmd);

        if ($result->stdout === '') {
            return [0, 0, []];
        }

        $rows = [];
        $skipped = 0;
        $samples = [];
        $knownIds = $gated ? [] : $this->knownRequestIds($server, $siteId);

        foreach (preg_split('/\r?\n/', $result->stdout) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if ($knownIds !== [] && preg_match(self::QUICK_REQ_ID_PATTERN, $line, $q) === 1 && isset($knownIds[$q[1]])) {
                continue;
            }

            $parsed = $this->parser->parse($line);

            if ($parsed === null) {
                $skipped++;

                if (count($samples) < self::MAX_SKIPPED_SAMPLES) {
                    $samples[] = mb_substr($line, 0, self::SKIPPED_SAMPLE_LENGTH);
                }

                continue;
            }

            $rows[] = array_merge($parsed, [
                'server_id' => $server->id,
                'site_id' => $siteId,
                'gated' => $gated,
                'imported_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($rows === []) {
            return [0, $skipped, $samples];
        }

        $rowsAffected = 0;
        $updateColumns = $gated ? ['gated', 'imported_at', 'updated_at'] : self::ACCESS_COLUMNS;

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $rowsAffected += DB::table('site_log_entries')->upsert($chunk, ['request_id'], $updateColumns);
        }

        return [$rowsAffected, $skipped, $samples];
    }

    /**
     * @return array<string, true> request_id => true for O(1) lookup
     */
    private function knownRequestIds(Server $server, string $siteId): array
    {
        $ids = DB::table('site_log_entries')
            ->where('server_id', $server->id)
            ->where('site_id', $siteId)
            ->where('occurred_at', '>=', now()->subHours(self::KNOWN_WINDOW_HOURS))
            ->pluck('request_id')
            ->all();

        return array_fill_keys($ids, true);
    }

    private function elapsed(float $start): int
    {
        return (int) ((microtime(true) - $start) * 1000);
    }
}
