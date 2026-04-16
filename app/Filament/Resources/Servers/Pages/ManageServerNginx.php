<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use App\Services\Nginx\Dto\NginxSaveResult;
use App\Services\Nginx\NginxManager;
use App\Services\Ssh\Exceptions\SshException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CodeEditor;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

class ManageServerNginx extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected string $view = 'filament.resources.servers.pages.manage-server-nginx';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog;

    public ?string $selectedPath = null;

    public ?string $fileContent = null;

    public ?string $draftContent = null;

    public ?string $openedHash = null;

    public bool $dirty = false;

    public bool $editing = false;

    public bool $loadingContent = false;

    /** @var list<array{path: string, group: string, relativePath: string}> */
    public array $files = [];

    /** @var array<string, list<string>> */
    public array $forgeDomains = [];

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
            $manager = app(NginxManager::class);
            $files = $manager->listFiles($this->getServer());
            $this->forgeDomains = $manager->listForgeDomains($this->getServer());
        } catch (SshException $e) {
            $this->files = [];
            $this->forgeDomains = [];
            $this->selectedPath = null;
            $this->fileContent = null;
            $this->resetDraft();

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
        $this->editing = false;

        try {
            $content = app(NginxManager::class)->readFile($this->getServer(), $path);
            $this->fileContent = $content;
            $this->draftContent = $content;
            $this->openedHash = hash('sha256', $content);
            $this->dirty = false;
        } catch (InvalidArgumentException|SshException $e) {
            $this->resetDraft();

            Notification::make()
                ->title('Unable to read file')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->loadingContent = false;
        }
    }

    public function updatedDraftContent(): void
    {
        $this->dirty = ($this->draftContent !== ($this->fileContent ?? ''));
    }

    public function toggleEdit(): void
    {
        if ($this->editing && $this->dirty) {
            $this->discardDraft();
        }

        $this->editing = ! $this->editing;
    }

    public function discardDraft(): void
    {
        $this->draftContent = $this->fileContent;
        $this->dirty = false;
    }

    public function saveDraft(): void
    {
        if ($this->selectedPath === null || $this->openedHash === null) {
            return;
        }

        try {
            $result = app(NginxManager::class)->saveFile(
                $this->getServer(),
                $this->selectedPath,
                (string) $this->draftContent,
                $this->openedHash,
            );
        } catch (InvalidArgumentException|SshException $e) {
            Notification::make()
                ->title('Save failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        match ($result->status) {
            'saved' => $this->onSaved($result),
            'stale' => Notification::make()
                ->title('File changed on disk')
                ->body('Reload the file to see the latest version.')
                ->danger()
                ->send(),
            'invalid_config' => Notification::make()
                ->title('Save refused — config invalid')
                ->body($result->output)
                ->danger()
                ->send(),
            default => Notification::make()
                ->title('Save failed')
                ->body($result->output)
                ->danger()
                ->send(),
        };
    }

    public function editor(Schema $schema): Schema
    {
        return $schema
            ->components([
                CodeEditor::make('draftContent')
                    ->hiddenLabel()
                    ->live(debounce: 400),
            ]);
    }

    public function getDiffProperty(): string
    {
        $original = $this->fileContent ?? '';
        $updated = $this->draftContent ?? '';

        if ($original === $updated) {
            return '';
        }

        $differ = new Differ(new UnifiedDiffOutputBuilder("--- current\n+++ draft\n", false));

        return $differ->diff($original, $updated);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->icon(Heroicon::OutlinedCheck)
                ->color('primary')
                ->visible(fn (): bool => $this->editing && $this->selectedPath !== null && $this->dirty)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => "Save {$this->selectedPath}")
                ->modalDescription('Review the change before writing it to the server. A backup is taken automatically.')
                ->modalContent(fn () => view('filament.resources.servers.partials.diff', [
                    'diff' => $this->diff,
                ]))
                ->action(fn () => $this->saveDraft()),

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

    private function onSaved(NginxSaveResult $result): void
    {
        $this->fileContent = $this->draftContent;
        $this->openedHash = hash('sha256', (string) $this->draftContent);
        $this->dirty = false;
        $this->editing = false;

        $body = $result->backupPath !== null
            ? "Backup saved at {$result->backupPath}. Reload nginx to apply."
            : 'Reload nginx to apply.';

        Notification::make()
            ->title('Saved')
            ->body($body)
            ->success()
            ->send();
    }

    private function resetDraft(): void
    {
        $this->draftContent = null;
        $this->openedHash = null;
        $this->dirty = false;
        $this->editing = false;
    }
}
