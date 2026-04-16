<?php

declare(strict_types=1);

namespace App\Services\Ssh\Contracts;

use App\Services\Ssh\Dto\CommandResult;

interface SshSession
{
    public function hostFingerprint(): string;

    public function authenticate(string $privateKey, ?string $passphrase = null): void;

    public function run(string $command, int $timeoutSeconds = 10): CommandResult;

    public function runPrivileged(string $command, string $sudoPassword, int $timeoutSeconds = 10): CommandResult;

    public function readFile(string $path): string;

    /**
     * @return list<string> absolute paths matching the glob
     */
    public function listFiles(string $pattern): array;

    public function writeFile(string $path, string $content): void;

    public function moveFile(string $from, string $to): void;

    public function deleteFile(string $path): void;

    public function fileExists(string $path): bool;

    public function disconnect(): void;
}
