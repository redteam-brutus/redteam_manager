<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Schemas;

use App\Models\SshKey;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
        ]);
    }
}
