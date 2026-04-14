<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Tables;

use App\Enums\ConnectionStatus;
use App\Filament\Resources\Servers\Actions\TestConnectionAction;
use App\Filament\Resources\Servers\Pages\ManageForgeSites;
use App\Filament\Resources\Servers\Pages\ManageServerAntibot;
use App\Filament\Resources\Servers\Pages\ManageServerLogs;
use App\Filament\Resources\Servers\Pages\ManageServerNginx;
use App\Filament\Resources\Servers\Pages\ManageServerOverview;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('host')
                    ->searchable(),

                TextColumn::make('port')
                    ->toggleable(),

                TextColumn::make('ssh_user')
                    ->label('User')
                    ->toggleable(),

                TextColumn::make('sshKey.name')
                    ->label('Key')
                    ->toggleable(),

                TextColumn::make('last_connection_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?ConnectionStatus $state): string => $state?->label() ?? 'Pending')
                    ->color(fn (?ConnectionStatus $state): string => $state?->color() ?? 'gray'),

                TextColumn::make('last_connected_at')
                    ->label('Last connected')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordUrl(fn ($record): string => ManageServerOverview::getUrl(['record' => $record]))
            ->recordActions([
                Action::make('overview')
                    ->label('Overview')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->color('primary')
                    ->url(fn ($record): string => ManageServerOverview::getUrl(['record' => $record])),
                TestConnectionAction::make(),
                Action::make('nginx')
                    ->label('Nginx')
                    ->icon(Heroicon::OutlinedCog)
                    ->color('gray')
                    ->url(fn ($record): string => ManageServerNginx::getUrl(['record' => $record])),
                Action::make('forge')
                    ->label('Forge sites')
                    ->icon(Heroicon::OutlinedCodeBracket)
                    ->color('gray')
                    ->url(fn ($record): string => ManageForgeSites::getUrl(['record' => $record])),
                Action::make('antibot')
                    ->label('Anti-bot')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('gray')
                    ->url(fn ($record): string => ManageServerAntibot::getUrl(['record' => $record])),
                Action::make('logs')
                    ->label('Logs')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->url(fn ($record): string => ManageServerLogs::getUrl(['record' => $record])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
