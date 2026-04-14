<?php

declare(strict_types=1);

namespace App\Filament\Resources\SshKeys\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SshKeyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            Textarea::make('private_key')
                ->label('Private key')
                ->helperText('Paste the OpenSSH private key. Leave blank on edit to keep the existing key.')
                ->rows(10)
                ->extraInputAttributes(['class' => 'font-mono text-xs'])
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->rule(function (string $operation) {
                    return function (string $attribute, mixed $value, \Closure $fail) use ($operation): void {
                        if ($operation === 'edit' && blank($value)) {
                            return;
                        }

                        if (! is_string($value) || ! str_contains($value, '-----BEGIN')) {
                            $fail('The :attribute must be a PEM-encoded private key.');
                        }
                    };
                }),

            TextInput::make('passphrase')
                ->password()
                ->revealable()
                ->maxLength(255)
                ->helperText('Optional passphrase that protects the private key.')
                ->dehydrated(fn (?string $state): bool => filled($state)),

            Textarea::make('public_key')
                ->label('Public key')
                ->rows(3)
                ->extraInputAttributes(['class' => 'font-mono text-xs'])
                ->disabled()
                ->dehydrated(false)
                ->visible(fn (string $operation): bool => $operation === 'edit'),
        ]);
    }
}
