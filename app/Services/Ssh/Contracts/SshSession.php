<?php

declare(strict_types=1);

namespace App\Services\Ssh\Contracts;

use App\Services\Ssh\Dto\CommandResult;

interface SshSession
{
    public function hostFingerprint(): string;

    public function authenticate(string $privateKey, ?string $passphrase = null): void;

    public function run(string $command, int $timeoutSeconds = 10): CommandResult;

    public function disconnect(): void;
}
