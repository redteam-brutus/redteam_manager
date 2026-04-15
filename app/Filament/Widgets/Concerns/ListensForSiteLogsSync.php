<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use Livewire\Attributes\On;

/**
 * Triggers a Livewire re-render when the Dashboard's "Sync now" action
 * finishes. Any widget that needs to reflect freshly ingested data can
 * `use` this trait — the empty method body is enough to force
 * re-evaluation of the widget's render-time data.
 */
trait ListensForSiteLogsSync
{
    #[On('site-logs-synced')]
    public function refreshFromSiteLogsSync(): void
    {
        //
    }
}
