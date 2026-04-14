<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use App\Services\Nginx\Dto\NginxSaveResult;
use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;
use App\Services\Nginx\Forge\ForgeSiteRegistry;
use App\Services\Nginx\Forge\ForgeSiteSettingsRenderer;
use App\Services\Nginx\NginxManager;
use App\Services\Ssh\Exceptions\SshException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class ManageForgeSites extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected string $view = 'filament.resources.servers.pages.manage-forge-sites';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    public ?string $selectedSiteId = null;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    /** @var list<array{siteId: string, managedPath: string, hasManaged: bool, domains: list<string>}> */
    public array $sites = [];

    public bool $hasManaged = false;

    public ?string $renderedPreview = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->loadSites();
    }

    public function getServer(): Server
    {
        /** @var Server $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getTitle(): string
    {
        return "Forge sites — {$this->getServer()->name}";
    }

    public function getBreadcrumb(): string
    {
        return 'Forge sites';
    }

    public function loadSites(): void
    {
        try {
            $sites = app(ForgeSiteRegistry::class)->list($this->getServer());
        } catch (SshException $e) {
            $this->sites = [];
            $this->selectedSiteId = null;
            $this->data = null;

            Notification::make()
                ->title('Unable to load Forge sites')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->sites = array_map(fn ($site): array => [
            'siteId' => $site->siteId,
            'managedPath' => $site->managedPath,
            'hasManaged' => $site->hasManaged,
            'domains' => $site->domains,
        ], $sites);
    }

    public function selectSite(string $siteId): void
    {
        try {
            $site = app(ForgeSiteRegistry::class)->find($this->getServer(), $siteId);
        } catch (InvalidArgumentException|SshException $e) {
            Notification::make()
                ->title('Unable to load site')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->selectedSiteId = $siteId;
        $this->hasManaged = $site->hasManaged;
        $this->data = [
            'analyticsEnabled' => $site->settings->analyticsEnabled,
            'trackingTag' => $site->settings->trackingTag,
            'scriptBody' => $site->settings->scriptBody,
            'conditionalAccessLog' => $site->settings->conditionalAccessLog,
            'accessLogPath' => $site->settings->accessLogPath,
            'gateNotBot' => $site->settings->gateNotBot,
            'gateHasFbclid' => $site->settings->gateHasFbclid,
            'gateIsTargetCountry' => $site->settings->gateIsTargetCountry,
            'gateIsTargetPage' => $site->settings->gateIsTargetPage,
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
                Section::make('Analytics injection')
                    ->description('Inject a <script> before the chosen tag via sub_filter.')
                    ->schema([
                        Toggle::make('analyticsEnabled')
                            ->label('Enabled')
                            ->live(debounce: 400),
                        Select::make('trackingTag')
                            ->options([
                                '</head>' => '</head>',
                                '</body>' => '</body>',
                                '<head>' => '<head>',
                                '<body>' => '<body>',
                            ])
                            ->default('</head>')
                            ->live(debounce: 400),
                        Textarea::make('scriptBody')
                            ->rows(6)
                            ->helperText('Inline <script>…</script> HTML. Keep single quotes escaped — renderer escapes them for nginx.')
                            ->live(debounce: 400),
                    ]),
                Section::make('Injection gates')
                    ->description('Gate sub_filter injection on global signals. $is_bot / $is_target_country / $is_target_page require the anti-bot conf.')
                    ->schema([
                        Toggle::make('gateNotBot')
                            ->label('Only real users (not bots)')
                            ->live(debounce: 400),
                        Toggle::make('gateHasFbclid')
                            ->label('Only when ?fbclid is present')
                            ->helperText('Emits a $has_fbclid helper map inline. No anti-bot dependency.')
                            ->live(debounce: 400),
                        Toggle::make('gateIsTargetCountry')
                            ->label('Only target countries')
                            ->live(debounce: 400),
                        Toggle::make('gateIsTargetPage')
                            ->label('Only target pages')
                            ->live(debounce: 400),
                    ]),
                Section::make('Conditional access log')
                    ->description('Log only ?fbclid=... traffic into a separate file.')
                    ->schema([
                        Toggle::make('conditionalAccessLog')
                            ->label('Enabled')
                            ->live(debounce: 400),
                        TextInput::make('accessLogPath')
                            ->label('Log file path')
                            ->placeholder('/var/log/nginx/example.com-fbclid.log')
                            ->live(debounce: 400),
                    ]),
            ]);
    }

    public function saveSite(): void
    {
        if ($this->selectedSiteId === null) {
            return;
        }

        $settings = $this->settingsFromData();

        try {
            $result = app(ForgeSiteRegistry::class)->save(
                $this->getServer(),
                $this->selectedSiteId,
                $settings,
            );
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
            $this->loadSites();
        }
    }

    public function disableSite(): void
    {
        if ($this->selectedSiteId === null) {
            return;
        }

        try {
            $result = app(ForgeSiteRegistry::class)->disable($this->getServer(), $this->selectedSiteId);
        } catch (InvalidArgumentException|SshException $e) {
            Notification::make()->title('Disable failed')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->dispatchSaveNotification($result);

        if ($result->ok) {
            $this->hasManaged = false;
            $this->data = [
                'analyticsEnabled' => false,
                'trackingTag' => '</head>',
                'scriptBody' => '',
                'conditionalAccessLog' => false,
                'accessLogPath' => '',
                'gateNotBot' => false,
                'gateHasFbclid' => false,
                'gateIsTargetCountry' => false,
                'gateIsTargetPage' => false,
            ];
            $this->refreshPreview();
            $this->loadSites();
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->icon(Heroicon::OutlinedCheck)
                ->color('primary')
                ->visible(fn (): bool => $this->selectedSiteId !== null)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => "Save Forge site {$this->selectedSiteId}")
                ->modalDescription('This writes a managed nginx snippet into forge-conf/<id>/server/. A backup is taken automatically.')
                ->action(fn () => $this->saveSite()),

            Action::make('disable')
                ->label('Disable')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn (): bool => $this->selectedSiteId !== null && $this->hasManaged)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => "Disable Forge site {$this->selectedSiteId}")
                ->modalDescription('Removes the managed file from the server. Forge config is untouched.')
                ->action(fn () => $this->disableSite()),

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
        if ($this->selectedSiteId === null) {
            $this->renderedPreview = null;

            return;
        }

        $this->renderedPreview = app(ForgeSiteSettingsRenderer::class)->render(
            $this->selectedSiteId,
            $this->settingsFromData(),
        );
    }

    private function settingsFromData(): ForgeSiteSettings
    {
        $data = $this->data ?? [];

        return new ForgeSiteSettings(
            analyticsEnabled: (bool) ($data['analyticsEnabled'] ?? false),
            trackingTag: (string) ($data['trackingTag'] ?? '</head>'),
            scriptBody: (string) ($data['scriptBody'] ?? ''),
            conditionalAccessLog: (bool) ($data['conditionalAccessLog'] ?? false),
            accessLogPath: (string) ($data['accessLogPath'] ?? ''),
            gateNotBot: (bool) ($data['gateNotBot'] ?? false),
            gateHasFbclid: (bool) ($data['gateHasFbclid'] ?? false),
            gateIsTargetCountry: (bool) ($data['gateIsTargetCountry'] ?? false),
            gateIsTargetPage: (bool) ($data['gateIsTargetPage'] ?? false),
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
