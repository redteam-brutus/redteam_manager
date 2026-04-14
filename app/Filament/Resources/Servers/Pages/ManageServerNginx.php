<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use App\Services\Nginx\NginxManager;
use App\Services\Ssh\Exceptions\SshException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class ManageServerNginx extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected string $view = 'filament.resources.servers.pages.manage-server-nginx';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog;

    public ?string $selectedPath = null;

    public ?string $fileContent = null;

    public bool $loadingContent = false;

    /** @var list<array{path: string, group: string, relativePath: string}> */
    public array $files = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->loadFiles();
    }

    public function getServer(): Server
    {
        /** @var Server $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getTitle(): string
    {
        return "Nginx — {$this->getServer()->name}";
    }

    public function getBreadcrumb(): string
    {
        return 'Nginx';
    }

    public function loadFiles(): void
    {
        try {
            $files = app(NginxManager::class)->listFiles($this->getServer());
        } catch (SshException $e) {
            $this->files = [];
            $this->selectedPath = null;
            $this->fileContent = null;

            Notification::make()
                ->title('Unable to load Nginx files')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->files = array_map(fn ($file): array => [
            'path' => $file->path,
            'group' => $file->group,
            'relativePath' => $file->relativePath,
        ], $files);
    }

    public function selectFile(string $path): void
    {
        $this->selectedPath = $path;
        $this->fileContent = null;
        $this->loadingContent = true;

        try {
            $this->fileContent = app(NginxManager::class)->readFile($this->getServer(), $path);
        } catch (InvalidArgumentException|SshException $e) {
            $this->fileContent = null;

            Notification::make()
                ->title('Unable to read file')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->loadingContent = false;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('validate')
                ->label('Validate config')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->action(fn () => $this->runValidate()),

            Action::make('reload')
                ->label('Reload nginx')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => "Reload nginx on {$this->getServer()->name}?")
                ->action(fn () => $this->runReload()),
        ];
    }

    public function runValidate(): void
    {
        try {
            $result = app(NginxManager::class)->validate($this->getServer());
        } catch (SshException $e) {
            Notification::make()->title('Unable to validate')->body($e->getMessage())->danger()->send();

            return;
        }

        $notification = Notification::make()
            ->title($result->ok ? 'Config valid' : 'Config invalid')
            ->body($result->output !== '' ? $result->output : null);

        $result->ok ? $notification->success()->send() : $notification->danger()->send();
    }

    public function runReload(): void
    {
        try {
            $result = app(NginxManager::class)->reload($this->getServer());
        } catch (SshException $e) {
            Notification::make()->title('Unable to reload')->body($e->getMessage())->danger()->send();

            return;
        }

        $notification = Notification::make()
            ->title($result->ok ? 'Nginx reloaded' : 'Reload refused')
            ->body($result->output !== '' ? $result->output : null);

        $result->ok ? $notification->success()->send() : $notification->danger()->send();
    }
}
