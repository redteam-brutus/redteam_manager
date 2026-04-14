<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConnectionStatus;
use App\Models\Server;
use App\Models\SshKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).'-server',
            'host' => fake()->ipv4(),
            'port' => 22,
            'ssh_user' => fake()->randomElement(['root', 'ubuntu', 'deploy']),
            'ssh_key_id' => SshKey::factory(),
            'host_fingerprint' => null,
            'last_connected_at' => null,
            'last_connection_status' => null,
            'last_connection_message' => null,
            'metadata' => null,
        ];
    }

    public function connected(): static
    {
        return $this->state([
            'host_fingerprint' => hash('sha256', 'known-host'),
            'last_connected_at' => now(),
            'last_connection_status' => ConnectionStatus::Success,
            'metadata' => ['whoami' => 'root', 'uname' => 'Linux'],
        ]);
    }
}
