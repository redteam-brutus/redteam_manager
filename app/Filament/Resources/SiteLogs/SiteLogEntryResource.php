<?php

declare(strict_types=1);

namespace App\Filament\Resources\SiteLogs;

use App\Filament\Resources\SiteLogs\Pages\ListSiteLogEntries;
use App\Filament\Resources\SiteLogs\Tables\SiteLogEntriesTable;
use App\Models\SiteLogEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SiteLogEntryResource extends Resource
{
    protected static ?string $model = SiteLogEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Site logs';

    protected static ?string $recordTitleAttribute = 'request_id';

    public static function table(Table $table): Table
    {
        return SiteLogEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSiteLogEntries::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
