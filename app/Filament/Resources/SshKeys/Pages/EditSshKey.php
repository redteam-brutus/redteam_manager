<?php

declare(strict_types=1);

namespace App\Filament\Resources\SshKeys\Pages;

use App\Filament\Resources\SshKeys\SshKeyResource;
use App\Models\SshKey;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use InvalidArgumentException;

class EditSshKey extends EditRecord
{
    protected static string $resource = SshKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! array_key_exists('private_key', $data) || blank($data['private_key'])) {
            return $data;
        }

        try {
            $derived = SshKey::deriveKeyMaterial(
                $data['private_key'],
                $data['passphrase'] ?? $this->record->passphrase,
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('data.private_key', $e->getMessage());
            $this->halt();
        }

        return array_merge($data, $derived ?? []);
    }
}
