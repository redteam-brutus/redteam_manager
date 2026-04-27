<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\AppSetting;
use BackedEnum;
use DateTimeZone;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class AppSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Cog6Tooth;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static ?string $slug = 'settings';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.app-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'timezone' => AppSetting::timezone(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Display')
                    ->description('How dates and times render in the panel.')
                    ->schema([
                        Select::make('timezone')
                            ->label('Timezone')
                            ->options(self::timezoneOptions())
                            ->searchable()
                            ->required()
                            ->helperText('Applied to Site Logs date column.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        AppSetting::set(AppSetting::TIMEZONE_KEY, $data['timezone']);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    private static function timezoneOptions(): array
    {
        $identifiers = DateTimeZone::listIdentifiers();
        $options = [];

        foreach ($identifiers as $identifier) {
            $options[$identifier] = $identifier;
        }

        return $options;
    }
}
