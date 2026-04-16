<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use App\Services\Nginx\Logs\LogViewer;
use App\Services\Ssh\Exceptions\SshException;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class ManageServerLogs extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected string $view = 'filament.resources.servers.pages.manage-server-logs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    /** @var list<array{path: string, filename: string}> */
    public array $logs = [];

    public ?string $selectedPath = null;

    /** @var list<string> */
    public array $lines = [];

    public bool $paused = false;

    public ?string $filter = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->loadLogs();
    }

    public function getServer(): Server
    {
        /** @var Server $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getTitle(): string
    {
        return "Logs — {$this->getServer()->name}";
    }

    public function getBreadcrumb(): string
    {
        return 'Logs';
    }

    public function loadLogs(): void
    {
        try {
            $logs = app(LogViewer::class)->list($this->getServer());
        } catch (SshException $e) {
            $this->logs = [];
            $this->selectedPath = null;
            $this->lines = [];

            Notification::make()
                ->title('Unable to list log files')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->logs = array_map(
            fn ($log): array => ['path' => $log->path, 'filename' => $log->filename],
            $logs,
        );

        if ($this->logs === []) {
            $this->selectedPath = null;
            $this->lines = [];

            return;
        }

        if ($this->selectedPath === null || ! $this->isKnownPath($this->selectedPath)) {
            $this->selectLog($this->logs[0]['path']);
        }
    }

    public function selectLog(string $path): void
    {
        if (! $this->isKnownPath($path)) {
            return;
        }

        $this->selectedPath = $path;
        $this->refreshLines();
    }

    public function togglePause(): void
    {
        $this->paused = ! $this->paused;
    }

    public function clearFilter(): void
    {
        $this->filter = null;
    }

    public function tick(): void
    {
        if ($this->paused || $this->selectedPath === null) {
            return;
        }

        $this->refreshLines();
    }

    /**
     * @return list<string>
     */
    public function getFilteredLinesProperty(): array
    {
        if ($this->filter === null || trim($this->filter) === '') {
            return $this->lines;
        }

        $pattern = '/'.str_replace('/', '\\/', $this->filter).'/';

        return array_values(array_filter($this->lines, function (string $line) use ($pattern): bool {
            return @preg_match($pattern, $line) === 1;
        }));
    }

    private function refreshLines(): void
    {
        if ($this->selectedPath === null) {
            return;
        }

        try {
            $output = app(LogViewer::class)->tail($this->getServer(), $this->selectedPath);
        } catch (InvalidArgumentException|SshException $e) {
            Notification::make()
                ->title('Unable to tail log')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $lines = preg_split('/\r?\n/', rtrim($output, "\r\n")) ?: [];
        $this->lines = array_slice($lines, -500);
    }

    private function isKnownPath(string $path): bool
    {
        foreach ($this->logs as $log) {
            if ($log['path'] === $path) {
                return true;
            }
        }

        return false;
    }
}
