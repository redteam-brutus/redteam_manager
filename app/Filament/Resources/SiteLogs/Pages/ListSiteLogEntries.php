<?php

declare(strict_types=1);

namespace App\Filament\Resources\SiteLogs\Pages;

use App\Filament\Resources\SiteLogs\SiteLogEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListSiteLogEntries extends ListRecords
{
    protected static string $resource = SiteLogEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
