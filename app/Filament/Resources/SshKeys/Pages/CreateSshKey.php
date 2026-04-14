<?php

declare(strict_types=1);

namespace App\Filament\Resources\SshKeys\Pages;

use App\Filament\Resources\SshKeys\SshKeyResource;
use App\Models\SshKey;
use Filament\Resources\Pages\CreateRecord;
use InvalidArgumentException;

class CreateSshKey extends CreateRecord
{
    protected static string $resource = SshKeyResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        try {
            $derived = SshKey::deriveKeyMaterial($data['private_key'], $data['passphrase'] ?? null);
        } catch (InvalidArgumentException $e) {
            $this->addError('data.private_key', $e->getMessage());
            $this->halt();
        }

        return array_merge($data, $derived ?? []);
    }
}
