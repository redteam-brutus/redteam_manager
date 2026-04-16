<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use App\Services\Nginx\Antibot\AntibotRegistry;
use App\Services\Nginx\Antibot\AntibotSettingsRenderer;
use App\Services\Nginx\Antibot\Dto\AntibotSettings;
use App\Services\Nginx\Dto\NginxSaveResult;
use App\Services\Nginx\NginxManager;
use App\Services\Ssh\Exceptions\SshException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class ManageServerAntibot extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected string $view = 'filament.resources.servers.pages.manage-server-antibot';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public bool $hasManaged = false;

    public ?string $renderedPreview = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->loadSettings();
    }

    public function getServer(): Server
    {
        /** @var Server $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getTitle(): string
    {
        return "Anti-bot — {$this->getServer()->name}";
    }

    public function getBreadcrumb(): string
    {
        return 'Anti-bot';
    }

    public function loadSettings(): void
    {
        try {
            $registry = app(AntibotRegistry::class);
            $settings = $registry->load($this->getServer());
            $this->hasManaged = $registry->hasManaged($this->getServer());
        } catch (SshException $e) {
            $this->data = null;
            $this->hasManaged = false;

            Notification::make()
                ->title('Unable to load anti-bot settings')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $botPatterns = $settings->botPatterns;

        if (! $this->hasManaged && $botPatterns === []) {
            $botPatterns = AntibotSettings::DEFAULT_BOT_PATTERNS;
        }

        $this->data = [
            'botPatterns' => $botPatterns,
        ];

        $this->refreshPreview();
    }

    public function updatedData(): void
    {
        $this->refreshPreview();
    }

    public function editor(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Bot user agents')
                    ->description('Regex fragments OR\'d together inside map $http_user_agent $is_bot. Target countries and pages are configured per Forge site.')
                    ->schema([
                        TagsInput::make('botPatterns')
                            ->placeholder('Add a UA fragment, e.g. googlebot')
                            ->helperText('Each tag is inserted verbatim into the alternation. Avoid pipes, unescaped parens, and newlines.')
                            ->live(debounce: 400),
                    ]),
            ]);
    }

    public function saveSettings(): void
    {
        $settings = $this->settingsFromData();

        try {
            $result = app(AntibotRegistry::class)->save($this->getServer(), $settings);
        } catch (InvalidArgumentException|SshException $e) {
            Notification::make()
                ->title('Save failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->dispatchSaveNotification($result);

        if ($result->ok) {
            $this->hasManaged = ! $settings->isEmpty();
            $this->refreshPreview();
            $this->autoReload();
        }
    }

    public function disableAntibot(): void
    {
        try {
            $result = app(AntibotRegistry::class)->disable($this->getServer());
        } catch (SshException $e) {
            Notification::make()->title('Disable failed')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->dispatchSaveNotification($result);

        if ($result->ok) {
            $this->hasManaged = false;
            $this->data = [
                'botPatterns' => [],
            ];
            $this->refreshPreview();
            $this->autoReload();
        }
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

    private function autoReload(): void
    {
        try {
            $result = app(NginxManager::class)->reload($this->getServer());
        } catch (SshException $e) {
            Notification::make()
                ->title('Saved, but auto-reload failed')
                ->body('Your change is valid on disk but nginx is still running the old config. Click Reload nginx to apply. ('.$e->getMessage().')')
                ->warning()
                ->send();

            return;
        }

        if (! $result->ok) {
            Notification::make()
                ->title('Saved, but auto-reload failed')
                ->body('Your change is valid on disk but nginx is still running the old config. Click Reload nginx to apply. ('.$result->output.')')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Nginx reloaded')
            ->body('Managed config is now live.')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->icon(Heroicon::OutlinedCheck)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Save anti-bot configuration')
                ->modalDescription('Writes a managed file to /etc/nginx/conf.d/redteam-antibot.conf. A backup is taken automatically.')
                ->action(fn () => $this->saveSettings()),

            Action::make('disable')
                ->label('Disable')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn (): bool => $this->hasManaged)
                ->requiresConfirmation()
                ->modalHeading('Disable anti-bot')
                ->modalDescription('Removes the managed file. Any server{} blocks referencing $is_bot will fail validation until their gates are removed.')
                ->action(fn () => $this->disableAntibot()),

            Action::make('reload')
                ->label('Reload nginx')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => "Reload nginx on {$this->getServer()->name}?")
                ->action(fn () => $this->runReload()),
        ];
    }

    private function refreshPreview(): void
    {
        try {
            $this->renderedPreview = app(AntibotSettingsRenderer::class)->render($this->settingsFromData());
        } catch (InvalidArgumentException) {
            $this->renderedPreview = null;
        }
    }

    private function settingsFromData(): AntibotSettings
    {
        $data = $this->data ?? [];

        return new AntibotSettings(
            botPatterns: array_values(array_map('strval', $data['botPatterns'] ?? [])),
        );
    }

    private function dispatchSaveNotification(NginxSaveResult $result): void
    {
        if ($result->ok) {
            Notification::make()
                ->title('Saved')
                ->body($result->output)
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title($result->status === 'invalid_config' ? 'Save refused — config invalid' : 'Save failed')
            ->body($result->output)
            ->danger()
            ->send();
    }
}
