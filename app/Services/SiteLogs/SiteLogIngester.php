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

    /**
     * Reserved canonical slug used for the always-on access pass that inserts new entry rows.
     */
    private const CANONICAL_SLUG = 'access';

    private const ACCESS_COLUMNS = [
        'occurred_at', 'host', 'remote_addr', 'uri', 'request_uri', 'fbclid', 'user_agent',
        'browser_name', 'browser_version', 'os_name', 'os_version', 'device_type', 'is_bot',
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
        $matchesAdded = 0;
        $matchCounts = [];
        $skipped = 0;
        $errors = [];
        $skippedSamples = [];

        try {
            $files = $this->enumerateLogFiles($server);
        } catch (SshException $e) {
            return new IngestReport(0, 0, 0, $this->elapsed($start), ["enumerate: {$e->getMessage()}"]);
        }

        // Canonical (`access`) pass first: this is the only pass allowed to insert entry rows.
        // It also writes its own pivot match row per inserted/known entry.
        foreach ($files[self::CANONICAL_SLUG] ?? [] as $siteId => $path) {
            $siteId = (string) $siteId;

            try {
                [$rowsInserted, $matchesForSlug, $linesSkipped, $samples] = $this->ingestCanonical($server, $siteId, $path);
                $inserted += $rowsInserted;
                $matchesAdded += $matchesForSlug;
                $matchCounts[self::CANONICAL_SLUG] = ($matchCounts[self::CANONICAL_SLUG] ?? 0) + $matchesForSlug;
                $skipped += $linesSkipped;
                $skippedSamples = $this->mergeSamples($skippedSamples, $samples);
            } catch (SshException $e) {
                $errors[] = "access ({$siteId}): {$e->getMessage()}";
            }
        }

        // Per-slug tag passes: never insert entry rows, only attach pivot matches for already-known requests.
        foreach ($files as $slug => $byId) {
            if ($slug === self::CANONICAL_SLUG) {
                continue;
            }

            foreach ($byId as $siteId => $path) {
                $siteId = (string) $siteId;

                try {
                    [$matchesForSlug, $linesSkipped, $samples] = $this->ingestTag($server, $siteId, $slug, $path);
                    $matchesAdded += $matchesForSlug;
                    $matchCounts[$slug] = ($matchCounts[$slug] ?? 0) + $matchesForSlug;
                    $skipped += $linesSkipped;
                    $skippedSamples = $this->mergeSamples($skippedSamples, $samples);
                } catch (SshException $e) {
                    $errors[] = "{$slug} ({$siteId}): {$e->getMessage()}";
                }
            }
        }

        return new IngestReport(
            rowsInserted: $inserted,
            rowsUpdated: $matchesAdded,
            linesSkipped: $skipped,
            durationMs: $this->elapsed($start),
            errors: $errors,
            skippedSamples: $skippedSamples,
            matchCounts: $matchCounts,
        );
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
     * Enumerate every `site-{id}-{slug}.log` in the nginx log dir, grouped by slug then site id.
     *
     * @return array<string, array<string, string>> slug => [siteId => path]
     */
    private function enumerateLogFiles(Server $server): array
    {
        $cmd = sprintf(
            'find %s -maxdepth 1 -type f -name %s 2>/dev/null',
            escapeshellarg($this->nginxLogDir),
            escapeshellarg('site-*.log'),
        );

        $result = $this->ssh->run($server, $cmd);
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $result->stdout) ?: []),
            fn (string $l): bool => $l !== '',
        ));

        /** @var array<string, array<string, string>> $bySlug */
        $bySlug = [];

        foreach ($lines as $path) {
            if (preg_match('#/site-(?<site>[^/]+)-(?<slug>[a-z][a-z0-9_]{0,31})\.log$#', $path, $m) !== 1) {
                continue;
            }

            $bySlug[$m['slug']][$m['site']] = $path;
        }

        return $bySlug;
    }

    /**
     * Canonical (`access`) pass — inserts new rows + writes the `access` pivot match for every
     * line in the file (subject to the dedup window).
     *
     * @return array{0:int,1:int,2:int,3:list<string>} [rowsInserted, matchesAdded, linesSkipped, skippedSamples]
     */
    private function ingestCanonical(Server $server, string $siteId, string $path): array
    {
        $cmd = sprintf('tail -n %d %s 2>/dev/null', self::TAIL_LINES, escapeshellarg($path));
        $result = $this->ssh->run($server, $cmd);

        if ($result->stdout === '') {
            return [0, 0, 0, []];
        }

        $rows = [];
        $skipped = 0;
        $samples = [];
        $knownIds = $this->knownRequestIds($server, $siteId);

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
                'imported_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($rows === []) {
            return [0, 0, $skipped, $samples];
        }

        $rowsAffected = 0;

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $rowsAffected += DB::table('site_log_entries')->upsert($chunk, ['request_id'], self::ACCESS_COLUMNS);
        }

        // Attach the canonical `access` pivot for every request id we saw on this pass.
        $requestIds = array_map(fn (array $r): string => (string) $r['request_id'], $rows);
        $matchesAdded = $this->attachMatches($server, $siteId, self::CANONICAL_SLUG, $requestIds);

        return [$rowsAffected, $matchesAdded, $skipped, $samples];
    }

    /**
     * Per-slug tag pass — never inserts new entry rows. Looks up entries by request_id
     * and inserts pivot match rows idempotently. Skips request ids already tagged with
     * this slug to avoid redundant pivot inserts.
     *
     * @return array{0:int,1:int,2:list<string>} [matchesAdded, linesSkipped, skippedSamples]
     */
    private function ingestTag(Server $server, string $siteId, string $slug, string $path): array
    {
        $cmd = sprintf('tail -n %d %s 2>/dev/null', self::TAIL_LINES, escapeshellarg($path));
        $result = $this->ssh->run($server, $cmd);

        if ($result->stdout === '') {
            return [0, 0, []];
        }

        $requestIds = [];
        $skipped = 0;
        $samples = [];
        $alreadyTagged = $this->knownTaggedRequestIds($server, $siteId, $slug);

        foreach (preg_split('/\r?\n/', $result->stdout) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match(self::QUICK_REQ_ID_PATTERN, $line, $q) === 1) {
                $requestId = $q[1];

                if (isset($alreadyTagged[$requestId])) {
                    continue;
                }

                $requestIds[$requestId] = true;

                continue;
            }

            // Fall back to full parse — line might still be valid but the quick pattern missed it.
            $parsed = $this->parser->parse($line);

            if ($parsed === null) {
                $skipped++;

                if (count($samples) < self::MAX_SKIPPED_SAMPLES) {
                    $samples[] = mb_substr($line, 0, self::SKIPPED_SAMPLE_LENGTH);
                }

                continue;
            }

            $requestId = (string) $parsed['request_id'];

            if (isset($alreadyTagged[$requestId])) {
                continue;
            }

            $requestIds[$requestId] = true;
        }

        if ($requestIds === []) {
            return [0, $skipped, $samples];
        }

        $matchesAdded = $this->attachMatches($server, $siteId, $slug, array_keys($requestIds));

        return [$matchesAdded, $skipped, $samples];
    }

    /**
     * Insert pivot match rows for every entry matching the supplied request ids on this server+site.
     * Idempotent via the composite unique index on (site_log_entry_id, log_slug).
     *
     * @param  list<string>  $requestIds
     */
    private function attachMatches(Server $server, string $siteId, string $slug, array $requestIds): int
    {
        if ($requestIds === []) {
            return 0;
        }

        $totalAdded = 0;
        $now = now();

        foreach (array_chunk(array_values(array_unique($requestIds)), self::CHUNK_SIZE) as $chunk) {
            $entryIds = DB::table('site_log_entries')
                ->where('server_id', $server->id)
                ->where('site_id', $siteId)
                ->whereIn('request_id', $chunk)
                ->pluck('id')
                ->all();

            if ($entryIds === []) {
                continue;
            }

            $payload = array_map(
                fn (int|string $id): array => [
                    'site_log_entry_id' => (int) $id,
                    'log_slug' => $slug,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $entryIds,
            );

            $totalAdded += DB::table('site_log_entry_log_matches')
                ->upsert($payload, ['site_log_entry_id', 'log_slug'], ['updated_at']);
        }

        return $totalAdded;
    }

    /**
     * Request ids already present in `site_log_entries` for this server+site within the
     * dedup window. Used by the canonical pass to avoid re-upserting recent rows.
     *
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

    /**
     * Request ids already tagged with the supplied slug for this server+site. Used by the
     * per-slug tag pass to avoid redundant pivot inserts.
     *
     * @return array<string, true> request_id => true for O(1) lookup
     */
    private function knownTaggedRequestIds(Server $server, string $siteId, string $slug): array
    {
        $ids = DB::table('site_log_entries')
            ->join('site_log_entry_log_matches', 'site_log_entries.id', '=', 'site_log_entry_log_matches.site_log_entry_id')
            ->where('site_log_entries.server_id', $server->id)
            ->where('site_log_entries.site_id', $siteId)
            ->where('site_log_entries.occurred_at', '>=', now()->subHours(self::KNOWN_WINDOW_HOURS))
            ->where('site_log_entry_log_matches.log_slug', $slug)
            ->pluck('site_log_entries.request_id')
            ->all();

        return array_fill_keys($ids, true);
    }

    private function elapsed(float $start): int
    {
        return (int) ((microtime(true) - $start) * 1000);
    }
}
