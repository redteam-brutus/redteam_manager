<?php

declare(strict_types=1);

namespace App\Services\SiteLogs\Dto;

final readonly class IngestReport
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $skippedSamples
     */
    public function __construct(
        public int $rowsInserted,
        public int $rowsUpdated,
        public int $linesSkipped,
        public int $durationMs,
        public array $errors,
        public array $skippedSamples = [],
    ) {}

    public function summary(): string
    {
        return sprintf(
            'inserted=%d updated=%d skipped=%d errors=%d duration=%dms',
            $this->rowsInserted,
            $this->rowsUpdated,
            $this->linesSkipped,
            count($this->errors),
            $this->durationMs,
        );
    }
}
