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
use App\Support\Countries;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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

        $requestedSiteId = request()->query('site');

        if (is_string($requestedSiteId) && $requestedSiteId !== '') {
            $exists = collect($this->sites)->contains(fn (array $s): bool => $s['siteId'] === $requestedSiteId);

            if ($exists) {
                $this->selectSite($requestedSiteId);
            }
        }
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

        $socialRefererHosts = $site->settings->socialRefererHosts;

        if (! $site->hasManaged && $socialRefererHosts === []) {
            $socialRefererHosts = ForgeSiteSettings::DEFAULT_SOCIAL_REFERER_HOSTS;
        }

        $this->selectedSiteId = $siteId;
        $this->hasManaged = $site->hasManaged;
        $this->data = [
            'analyticsEnabled' => $site->settings->analyticsEnabled,
            'trackingTag' => $site->settings->trackingTag,
            'scriptBody' => $site->settings->scriptBody,
            'siteLoggingEnabled' => $site->settings->siteLoggingEnabled,
            'gateNotBot' => $site->settings->gateNotBot,
            'gateHasFbclid' => $site->settings->gateHasFbclid,
            'gateIsTargetCountry' => $site->settings->gateIsTargetCountry,
            'gateIsTargetPage' => $site->settings->gateIsTargetPage,
            'targetCountries' => $site->settings->targetCountries,
            'targetPages' => array_map(fn (string $p): array => ['pattern' => $p], $site->settings->targetPages),
            'socialRefererHosts' => $socialRefererHosts,
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
                            ->placeholder("<script>console.log('hello');</script>")
                            ->helperText('Auto-wrapped in <script>…</script> if you don\'t include any <script> tag. Paste your own wrapper to keep attributes like src/async/defer.')
                            ->live(debounce: 400),
                    ]),
                Section::make('Injection gates')
                    ->description('Gate sub_filter injection on request signals. Bot detection is shared via anti-bot; fbclid, country and page lists are per-site.')
                    ->schema([
                        Toggle::make('gateNotBot')
                            ->label('Only real users (not bots)')
                            ->helperText('Requires anti-bot conf (provides $is_bot).')
                            ->live(debounce: 400),
                        Toggle::make('gateHasFbclid')
                            ->label('Only entry-proof traffic (fbclid or social referer)')
                            ->helperText('Empty host list below: fbclid-only. Non-empty: fbclid OR matching referer.')
                            ->live(debounce: 400),
                        TagsInput::make('socialRefererHosts')
                            ->label('Social referer hosts (OR with fbclid)')
                            ->placeholder('facebook.com')
                            ->helperText('When set, injection fires for ?fbclid= OR requests whose Referer matches one of these hosts. Leave empty to keep the gate as fbclid-only.')
                            ->visible(fn (Get $get): bool => (bool) $get('gateHasFbclid'))
                            ->live(debounce: 400),
                        Toggle::make('gateIsTargetCountry')
                            ->label('Only target countries')
                            ->live(debounce: 400),
                        Toggle::make('gateIsTargetPage')
                            ->label('Only target pages')
                            ->live(debounce: 400),
                    ]),
                Section::make('Target scope (per-site)')
                    ->description('Countries and page URIs that define $site_<id>_is_target_country and $site_<id>_is_target_page. Each site has its own list — no cross-site bleed.')
                    ->schema([
                        Select::make('targetCountries')
                            ->label('Target countries')
                            ->multiple()
                            ->searchable()
                            ->native(false)
                            ->options(Countries::options())
                            ->helperText('Cloudflare country codes. Active when the "Only target countries" gate is on.')
                            ->live(debounce: 400),
                        Repeater::make('targetPages')
                            ->label('Target page URI regexes')
                            ->simple(
                                TextInput::make('pattern')
                                    ->required()
                                    ->placeholder('^/offer/'),
                            )
                            ->addActionLabel('Add a target page')
                            ->reorderable(false)
                            ->default([])
                            ->live(debounce: 400),
                    ]),
                Section::make('Site traffic logs')
                    ->description('Writes two verbose logs per site — access.log (every request) and gate.log (only requests that matched all enabled gates, i.e. the ones that triggered injection). Forge\'s site.conf contains `access_log off;`, which would silence both logs — we comment it out automatically when you enable this, and restore it when you disable.')
                    ->schema([
                        Toggle::make('siteLoggingEnabled')
                            ->label('Enable verbose site logging')
                            ->helperText(fn (): string => $this->selectedSiteId !== null
                                ? 'Writes '.ForgeSiteSettingsRenderer::defaultSiteAccessLogPath($this->selectedSiteId).' (all traffic) and '.ForgeSiteSettingsRenderer::defaultSiteGateLogPath($this->selectedSiteId).' (matched gates).'
                                : 'Writes /var/log/nginx/site-<id>-access.log (all traffic) and /var/log/nginx/site-<id>-gate.log (matched gates).')
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
            $this->autoReload();
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
                'siteLoggingEnabled' => false,
                'gateNotBot' => false,
                'gateHasFbclid' => false,
                'gateIsTargetCountry' => false,
                'gateIsTargetPage' => false,
                'targetCountries' => [],
                'targetPages' => [],
                'socialRefererHosts' => [],
            ];
            $this->refreshPreview();
            $this->loadSites();
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

        $rendered = app(ForgeSiteSettingsRenderer::class)->render(
            $this->selectedSiteId,
            $this->settingsFromData(),
        );

        $httpPath = "/etc/nginx/conf.d/redteam-forge-{$this->selectedSiteId}.conf";
        $serverPath = "/etc/nginx/forge-conf/{$this->selectedSiteId}/server/redteam-analytics.conf";

        $sections = [];

        if ($rendered->httpContext !== '') {
            $sections[] = "# → {$httpPath}\n\n".$rendered->httpContext;
        }

        if ($rendered->serverContext !== '') {
            $sections[] = "# → {$serverPath}\n\n".$rendered->serverContext;
        }

        $this->renderedPreview = $sections === [] ? null : implode("\n\n", $sections);
    }

    private function settingsFromData(): ForgeSiteSettings
    {
        $data = $this->data ?? [];

        $pages = array_values(array_filter(
            array_map(
                fn ($row): string => is_array($row) ? (string) ($row['pattern'] ?? '') : (string) $row,
                (array) ($data['targetPages'] ?? []),
            ),
            fn (string $p): bool => $p !== '',
        ));

        $socialHosts = array_values(array_filter(
            array_map('strval', (array) ($data['socialRefererHosts'] ?? [])),
            fn (string $h): bool => $h !== '',
        ));

        return new ForgeSiteSettings(
            analyticsEnabled: (bool) ($data['analyticsEnabled'] ?? false),
            trackingTag: (string) ($data['trackingTag'] ?? '</head>'),
            scriptBody: (string) ($data['scriptBody'] ?? ''),
            siteLoggingEnabled: (bool) ($data['siteLoggingEnabled'] ?? false),
            gateNotBot: (bool) ($data['gateNotBot'] ?? false),
            gateHasFbclid: (bool) ($data['gateHasFbclid'] ?? false),
            gateIsTargetCountry: (bool) ($data['gateIsTargetCountry'] ?? false),
            gateIsTargetPage: (bool) ($data['gateIsTargetPage'] ?? false),
            targetCountries: array_values(array_map('strval', (array) ($data['targetCountries'] ?? []))),
            targetPages: $pages,
            socialRefererHosts: $socialHosts,
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
