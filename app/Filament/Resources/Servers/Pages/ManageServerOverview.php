<?php

declare(strict_types=1);

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use App\Services\Nginx\Antibot\AntibotRegistry;
use App\Services\Nginx\Antibot\Dto\AntibotSettings;
use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;
use App\Services\Nginx\Forge\ForgeSiteRegistry;
use App\Services\Nginx\NginxManager;
use App\Services\Ssh\Exceptions\SshException;
use App\Support\Countries;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class ManageServerOverview extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected string $view = 'filament.resources.servers.pages.manage-server-overview';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    /** @var array{botCount:int,hasManaged:bool} */
    public array $antibotSummary = [
        'botCount' => 0,
        'hasManaged' => false,
    ];

    /** @var list<array{siteId:string,firstDomain:?string,domains:list<string>,hasManaged:bool,analyticsEnabled:bool,gates:list<string>,countries:list<string>,pageCount:int}> */
    public array $forgeSites = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->refreshSummaries();
    }

    public function getServer(): Server
    {
        /** @var Server $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getTitle(): string
    {
        return "Overview — {$this->getServer()->name}";
    }

    public function getBreadcrumb(): string
    {
        return 'Overview';
    }

    public function refreshSummaries(): void
    {
        try {
            $antibot = app(AntibotRegistry::class)->load($this->getServer());
            $hasManaged = app(AntibotRegistry::class)->hasManaged($this->getServer());

            $this->antibotSummary = [
                'botCount' => count($antibot->botPatterns),
                'hasManaged' => $hasManaged,
            ];

            $sites = app(ForgeSiteRegistry::class)->list($this->getServer());
        } catch (SshException $e) {
            $this->forgeSites = [];

            Notification::make()
                ->title('Unable to load server state')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->forgeSites = array_map(function ($site): array {
            $settings = $site->hasManaged
                ? app(ForgeSiteRegistry::class)->find($this->getServer(), $site->siteId)->settings
                : new ForgeSiteSettings;

            return [
                'siteId' => $site->siteId,
                'firstDomain' => $site->domains[0] ?? null,
                'domains' => $site->domains,
                'hasManaged' => $site->hasManaged,
                'analyticsEnabled' => $settings->analyticsEnabled,
                'gates' => $this->gateLabels($settings),
                'countries' => $settings->targetCountries,
                'pageCount' => count($settings->targetPages),
            ];
        }, $sites);
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

    /**
     * @param  array<string, mixed>  $data
     */
    public function applyCampaign(array $data): void
    {
        $server = $this->getServer();
        $antibotRegistry = app(AntibotRegistry::class);
        $forgeRegistry = app(ForgeSiteRegistry::class);

        $gateNotBot = (bool) ($data['gateNotBot'] ?? false);
        $gateHasFbclid = (bool) ($data['gateHasFbclid'] ?? false);
        $gateIsTargetCountry = (bool) ($data['gateIsTargetCountry'] ?? false);
        $gateIsTargetPage = (bool) ($data['gateIsTargetPage'] ?? false);

        $targetCountries = array_values(array_filter(array_map('strval', (array) ($data['targetCountries'] ?? []))));
        $targetPages = array_values(array_filter(array_map(
            fn ($row): string => is_array($row) ? (string) ($row['pattern'] ?? '') : (string) $row,
            (array) ($data['targetPages'] ?? []),
        ), fn (string $p): bool => $p !== ''));

        if ($gateNotBot) {
            $antibot = $antibotRegistry->load($server);

            if ($antibot->isEmpty()) {
                try {
                    $antibotResult = $antibotRegistry->save($server, new AntibotSettings(
                        botPatterns: AntibotSettings::DEFAULT_BOT_PATTERNS,
                    ));
                } catch (InvalidArgumentException|SshException $e) {
                    Notification::make()->title('Campaign failed — anti-bot seed')->body($e->getMessage())->danger()->send();

                    return;
                }

                if (! $antibotResult->ok) {
                    Notification::make()
                        ->title('Campaign failed — anti-bot seed')
                        ->body($antibotResult->output)
                        ->danger()
                        ->send();

                    return;
                }
            }
        }

        $forgeSiteIds = (array) ($data['forgeSiteIds'] ?? []);
        $scriptBody = trim((string) ($data['scriptBody'] ?? ''));

        if ($scriptBody === '' || $forgeSiteIds === []) {
            $this->refreshSummaries();

            Notification::make()
                ->title('Campaign nothing to apply')
                ->body('No Forge sites selected or script body empty.')
                ->warning()
                ->send();

            return;
        }

        $forgeSettingsFactory = fn (): ForgeSiteSettings => new ForgeSiteSettings(
            analyticsEnabled: true,
            trackingTag: (string) ($data['trackingTag'] ?? '</head>'),
            scriptBody: $scriptBody,
            gateNotBot: $gateNotBot,
            gateHasFbclid: $gateHasFbclid,
            gateIsTargetCountry: $gateIsTargetCountry,
            gateIsTargetPage: $gateIsTargetPage,
            targetCountries: $targetCountries,
            targetPages: $targetPages,
        );

        $applied = 0;

        foreach ($forgeSiteIds as $siteId) {
            try {
                $result = $forgeRegistry->save($server, (string) $siteId, $forgeSettingsFactory());
            } catch (InvalidArgumentException|SshException $e) {
                $this->refreshSummaries();

                Notification::make()
                    ->title("Campaign partially applied — site {$siteId} failed")
                    ->body("{$applied} site(s) saved. Site {$siteId}: {$e->getMessage()}")
                    ->danger()
                    ->send();

                return;
            }

            if (! $result->ok) {
                $this->refreshSummaries();

                Notification::make()
                    ->title("Campaign partially applied — site {$siteId} failed")
                    ->body("{$applied} site(s) saved. Site {$siteId}: {$result->output}")
                    ->danger()
                    ->send();

                return;
            }

            $applied++;
        }

        $reloadSuffix = $this->reloadAfterCampaign();

        $this->refreshSummaries();

        Notification::make()
            ->title('Campaign applied')
            ->body("Injection written to {$applied} Forge site(s).{$reloadSuffix}")
            ->success()
            ->send();
    }

    private function reloadAfterCampaign(): string
    {
        try {
            $result = app(NginxManager::class)->reload($this->getServer());
        } catch (SshException $e) {
            return ' Auto-reload failed: '.$e->getMessage().' — click Reload nginx to apply.';
        }

        if (! $result->ok) {
            return ' Auto-reload failed: '.$result->output.' — click Reload nginx to apply.';
        }

        return ' Nginx reloaded.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('campaign')
                ->label('New campaign')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('primary')
                ->steps([
                    Step::make('Target scope')
                        ->description('Which Forge sites, which countries, which URIs?')
                        ->schema([
                            Select::make('forgeSiteIds')
                                ->label('Forge sites')
                                ->multiple()
                                ->searchable()
                                ->native(false)
                                ->options(fn (): array => collect($this->forgeSites)
                                    ->mapWithKeys(fn (array $s): array => [
                                        $s['siteId'] => $s['domains'] !== []
                                            ? "{$s['siteId']} — ".implode(', ', $s['domains'])
                                            : $s['siteId'],
                                    ])
                                    ->all())
                                ->helperText('Search by site id or any of the domains. The server/ subfolder is excluded automatically.'),
                            Select::make('targetCountries')
                                ->label('Target countries')
                                ->multiple()
                                ->searchable()
                                ->native(false)
                                ->options(Countries::options()),
                            Repeater::make('targetPages')
                                ->label('Target page URI regexes')
                                ->simple(
                                    TextInput::make('pattern')
                                        ->required()
                                        ->placeholder('^/my-page/'),
                                )
                                ->addActionLabel('Add target page')
                                ->reorderable(false)
                                ->default([]),
                        ]),
                    Step::make('Gating')
                        ->description('Who sees the injection?')
                        ->schema([
                            Toggle::make('gateNotBot')
                                ->label('Only real users (not bots)')
                                ->default(true),
                            Toggle::make('gateHasFbclid')
                                ->label('Only when ?fbclid is present')
                                ->default(true),
                            Toggle::make('gateIsTargetCountry')
                                ->label('Only target countries')
                                ->default(false)
                                ->afterStateHydrated(function (Toggle $component, $state, Get $get) {
                                    if ($state === null || $state === false) {
                                        $component->state(! empty($get('../targetCountries') ?? []));
                                    }
                                }),
                            Toggle::make('gateIsTargetPage')
                                ->label('Only target pages')
                                ->default(false)
                                ->afterStateHydrated(function (Toggle $component, $state, Get $get) {
                                    if ($state === null || $state === false) {
                                        $component->state(! empty($get('../targetPages') ?? []));
                                    }
                                }),
                        ]),
                    Step::make('Injection')
                        ->description('What to inject into matching responses.')
                        ->schema([
                            Select::make('trackingTag')
                                ->label('Tracking tag')
                                ->options([
                                    '</head>' => '</head>',
                                    '</body>' => '</body>',
                                    '<head>' => '<head>',
                                    '<body>' => '<body>',
                                ])
                                ->default('</head>')
                                ->native(false),
                            Textarea::make('scriptBody')
                                ->label('Script body')
                                ->rows(8)
                                ->placeholder("<script>console.log('hello');</script>")
                                ->helperText('Auto-wrapped in <script>…</script> if you don\'t include any <script> tag. Paste your own wrapper to keep attributes like src/async/defer. Single quotes are escaped automatically for nginx.'),
                        ]),
                    Step::make('Review')
                        ->description('Apply the campaign.')
                        ->schema([]),
                ])
                ->action(fn (array $data) => $this->applyCampaign($data)),

            Action::make('validate')
                ->label('Validate nginx')
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

    /**
     * @return list<string>
     */
    private function gateLabels(ForgeSiteSettings $settings): array
    {
        $labels = [];

        if ($settings->gateNotBot) {
            $labels[] = 'not-bot';
        }

        if ($settings->gateHasFbclid) {
            $labels[] = 'fbclid';
        }

        if ($settings->gateIsTargetCountry) {
            $labels[] = 'target-country';
        }

        if ($settings->gateIsTargetPage) {
            $labels[] = 'target-page';
        }

        return $labels;
    }
}
