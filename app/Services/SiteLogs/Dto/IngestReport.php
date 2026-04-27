<?php

declare(strict_types=1);

namespace App\Services\SiteLogs\Dto;

final readonly class IngestReport
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $skippedSamples
     * @param  array<string, int>  $matchCounts  Map of log_slug => number of pivot rows attached this run.
     */
    public function __construct(
        public int $rowsInserted,
        public int $rowsUpdated,
        public int $linesSkipped,
        public int $durationMs,
        public array $errors,
        public array $skippedSamples = [],
        public array $matchCounts = [],
    ) {}

    public function summary(): string
    {
        return sprintf(
            'inserted=%d updated=%d skipped=%d errors=%d duration=%dms%s',
            $this->rowsInserted,
            $this->rowsUpdated,
            $this->linesSkipped,
            count($this->errors),
            $this->durationMs,
            $this->matchCounts === [] ? '' : ' matches='.$this->formatMatchCounts(),
        );
    }

    private function formatMatchCounts(): string
    {
        $parts = [];

        foreach ($this->matchCounts as $slug => $count) {
            $parts[] = "{$slug}:{$count}";
        }

        return implode(',', $parts);
    }
}
