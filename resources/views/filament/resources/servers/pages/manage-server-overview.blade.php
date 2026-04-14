<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Anti-bot</x-slot>
            <x-slot name="afterHeader">
                <x-filament::link wire:click="refreshSummaries" color="gray" size="xs">
                    Refresh
                </x-filament::link>
            </x-slot>

            @if (! $antibotSummary['hasManaged'])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Not configured yet. The anti-bot page will pre-fill a default bot list on first visit.
                </p>
            @else
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <x-filament::badge color="gray">
                        {{ $antibotSummary['botCount'] }} bot patterns
                    </x-filament::badge>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Target countries + pages are configured per Forge site.
                    </span>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Forge sites ({{ count($forgeSites) }})</x-slot>

            @if (empty($forgeSites))
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No Forge sites discovered. The server needs forge-conf/*/site.conf entries to appear here.
                </p>
            @else
                <ul class="space-y-3">
                    @foreach ($forgeSites as $site)
                        <li class="rounded-md border border-gray-200 p-3 dark:border-white/10">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-mono text-xs text-gray-700 dark:text-gray-300">
                                    {{ $site['siteId'] }}
                                    @if ($site['firstDomain'])
                                        <span class="text-gray-500 dark:text-gray-400"> — {{ $site['firstDomain'] }}</span>
                                    @endif
                                </span>
                                @if ($site['analyticsEnabled'])
                                    <x-filament::badge color="success" size="xs">injection on</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray" size="xs">injection off</x-filament::badge>
                                @endif
                            </div>
                            @if (! empty($site['domains']))
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($site['domains'] as $domain)
                                        <x-filament::badge color="gray" size="xs">{{ $domain }}</x-filament::badge>
                                    @endforeach
                                </div>
                            @endif
                            @if (! empty($site['gates']))
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($site['gates'] as $gate)
                                        <x-filament::badge color="warning" size="xs">{{ $gate }}</x-filament::badge>
                                    @endforeach
                                </div>
                            @endif
                            @if (! empty($site['countries']) || $site['pageCount'] > 0)
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($site['countries'] as $country)
                                        <x-filament::badge color="primary" size="xs">{{ $country }}</x-filament::badge>
                                    @endforeach
                                    @if ($site['pageCount'] > 0)
                                        <x-filament::badge color="primary" size="xs">{{ $site['pageCount'] }} target page{{ $site['pageCount'] === 1 ? '' : 's' }}</x-filament::badge>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
