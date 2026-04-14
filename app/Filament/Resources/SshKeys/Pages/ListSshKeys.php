<?php

namespace App\Filament\Resources\SshKeys\Pages;

use App\Filament\Resources\SshKeys\SshKeyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSshKeys extends ListRecords
{
    protected static string $resource = SshKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
