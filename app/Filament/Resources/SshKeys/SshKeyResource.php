<?php

declare(strict_types=1);

namespace App\Filament\Resources\SshKeys;

use App\Filament\Resources\SshKeys\Pages\CreateSshKey;
use App\Filament\Resources\SshKeys\Pages\EditSshKey;
use App\Filament\Resources\SshKeys\Pages\ListSshKeys;
use App\Filament\Resources\SshKeys\Schemas\SshKeyForm;
use App\Filament\Resources\SshKeys\Tables\SshKeysTable;
use App\Models\SshKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SshKeyResource extends Resource
{
    protected static ?string $model = SshKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $modelLabel = 'SSH key';

    protected static ?string $pluralModelLabel = 'SSH keys';

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return SshKeyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SshKeysTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSshKeys::route('/'),
            'create' => CreateSshKey::route('/create'),
            'edit' => EditSshKey::route('/{record}/edit'),
        ];
    }
}
