<?php

declare(strict_types=1);

namespace App\Services\Ssh\Exceptions;

class HostKeyMismatchException extends SshException
{
    public function __construct(
        public readonly string $expectedFingerprint,
        public readonly string $actualFingerprint,
    ) {
        parent::__construct(
            "Host key mismatch. Expected {$expectedFingerprint}, got {$actualFingerprint}."
        );
    }
}
