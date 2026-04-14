<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Schemas;

use App\Models\SshKey;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('host')
                ->label('Host / IP')
                ->required()
                ->maxLength(255),

            TextInput::make('port')
                ->numeric()
                ->default(22)
                ->minValue(1)
                ->maxValue(65535)
                ->required(),

            TextInput::make('ssh_user')
                ->label('SSH user')
                ->default('root')
                ->required()
                ->maxLength(255),

            Select::make('ssh_key_id')
                ->label('SSH key')
                ->relationship('sshKey', 'name')
                ->options(fn () => SshKey::query()->pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->required(),

            Toggle::make('use_sudo')
                ->label('Use sudo for privileged commands')
                ->helperText('Enable if the SSH user needs sudo for commands like `nginx -t` and `systemctl reload nginx`.')
                ->live(),

            TextInput::make('sudo_password')
                ->label('Sudo password')
                ->password()
                ->revealable()
                ->maxLength(255)
                ->helperText('Stored encrypted. Leave blank on edit to keep the existing password.')
                ->visible(fn (Get $get): bool => (bool) $get('use_sudo'))
                ->required(fn (Get $get, string $operation): bool => $operation === 'create' && (bool) $get('use_sudo'))
                ->dehydrated(fn (?string $state): bool => filled($state)),
        ]);
    }
}
