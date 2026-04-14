<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SshKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SshKey>
 */
class SshKeyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).'-key',
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nFAKEKEY\n-----END OPENSSH PRIVATE KEY-----\n",
            'public_key' => 'ssh-ed25519 AAAA'.fake()->regexify('[A-Za-z0-9]{40}').' fake@example.com',
            'passphrase' => null,
            'fingerprint' => 'SHA256:'.fake()->regexify('[A-Za-z0-9+/]{43}'),
        ];
    }

    public function withPassphrase(string $passphrase = 'secret'): static
    {
        return $this->state(['passphrase' => $passphrase]);
    }
}
