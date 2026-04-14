<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Actions;

use App\Enums\ConnectionStatus;
use App\Models\Server;
use App\Services\Ssh\SshConnectionManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class TestConnectionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'testConnection';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Test connection')
            ->icon(Heroicon::OutlinedBolt)
            ->color('primary')
            ->requiresConfirmation(false)
            ->action(function (Server $record, SshConnectionManager $manager): void {
                $result = $manager->testConnection($record->refresh());

                $notification = Notification::make()
                    ->title(match ($result->status) {
                        ConnectionStatus::Success => 'SSH connection succeeded',
                        ConnectionStatus::HostMismatch => 'Host key mismatch',
                        default => 'SSH connection failed',
                    });

                $body = match ($result->status) {
                    ConnectionStatus::Success => trim(sprintf(
                        "whoami: %s\nuname: %s",
                        $result->metadata['whoami'] ?? '',
                        $result->metadata['uname'] ?? '',
                    )),
                    default => $result->message ?? 'Unknown error.',
                };

                $notification = $notification->body($body);

                match ($result->status) {
                    ConnectionStatus::Success => $notification->success()->send(),
                    ConnectionStatus::HostMismatch => $notification->warning()->send(),
                    default => $notification->danger()->send(),
                };
            });
    }
}
