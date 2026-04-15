<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SiteLogs\SiteLogRefresher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncNow')
                ->label('Sync now')
                ->icon(Heroicon::ArrowPath)
                ->color('primary')
                ->action(function (): void {
                    $report = app(SiteLogRefresher::class)->syncNow();

                    $errors = $report['errors'] ?? [];
                    $samples = $report['skipped_samples'] ?? [];
                    $total = (int) $report['inserted'] + (int) $report['updated'];

                    $body = sprintf(
                        'Inserted %d · Updated %d · Skipped %d · %d ms',
                        $report['inserted'],
                        $report['updated'],
                        $report['skipped'],
                        $report['duration_ms'],
                    );

                    if ($errors !== []) {
                        $body .= "\n\nErrors:\n".implode("\n", array_slice($errors, 0, 5));
                    }

                    if ((int) $report['skipped'] > 0 && $samples !== []) {
                        $body .= "\n\nSkipped samples (parser rejected):\n".implode("\n", array_slice($samples, 0, 3));
                    }

                    $notification = Notification::make()
                        ->title($errors === [] ? 'Ingest complete' : 'Ingest finished with errors')
                        ->body($body);

                    if ($errors !== []) {
                        $notification->warning();
                    } elseif ((int) $report['skipped'] > 0 && $total === 0) {
                        $notification->warning()->title('Ingest parsed nothing');
                    } elseif ($total > 0) {
                        $notification->success();
                    } else {
                        $notification->info();
                    }

                    $notification->persistent()->send();

                    $this->dispatch('site-logs-synced');
                }),
        ];
    }
}
