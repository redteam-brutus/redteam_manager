<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <aside class="lg:col-span-4 xl:col-span-3">
            <x-filament::section>
                <x-slot name="heading">Forge sites</x-slot>

                <x-slot name="afterHeader">
                    <x-filament::link wire:click="loadSites" color="gray" size="xs">
                        Refresh
                    </x-filament::link>
                </x-slot>

                @if (empty($sites))
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No Forge sites discovered. The server needs forge-conf/*/site.conf files to appear here.
                    </p>
                @else
                    <ul class="space-y-1">
                        @foreach ($sites as $site)
                            <li>
                                <button
                                    type="button"
                                    wire:click="selectSite('{{ $site['siteId'] }}')"
                                    @class([
                                        'block w-full rounded-md px-2 py-2 text-left font-mono text-xs transition',
                                        'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => $selectedSiteId === $site['siteId'],
                                        'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $selectedSiteId !== $site['siteId'],
                                    ])
                                    title="{{ $site['managedPath'] }}"
                                >
                                    <div class="flex items-center justify-between">
                                        <span>{{ $site['siteId'] }}</span>
                                        @if ($site['hasManaged'])
                                            <x-filament::badge color="success" size="xs">managed</x-filament::badge>
                                        @endif
                                    </div>
                                    @if (! empty($site['domains']))
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach ($site['domains'] as $domain)
                                                <x-filament::badge color="gray" size="xs">{{ $domain }}</x-filament::badge>
                                            @endforeach
                                        </div>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </aside>

        <section class="lg:col-span-8 xl:col-span-9 space-y-6">
            @if ($selectedSiteId)
                {{ $this->editor }}

                <x-filament::section collapsible collapsed>
                    <x-slot name="heading">Rendered preview</x-slot>
                    <x-slot name="description">What will be written to forge-conf/{{ $selectedSiteId }}/server/redteam-analytics.conf</x-slot>

                    @if ($renderedPreview === null || $renderedPreview === '')
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Nothing to write — with all toggles off, Save will remove the managed file.
                        </p>
                    @else
                        <pre class="overflow-auto whitespace-pre rounded-md bg-gray-50 p-4 font-mono text-xs leading-relaxed text-gray-800 dark:bg-gray-950 dark:text-gray-200">{{ $renderedPreview }}</pre>
                    @endif
                </x-filament::section>
            @else
                <x-filament::section>
                    <x-filament::empty-state>
                        <x-slot name="heading">No site selected</x-slot>
                        <x-slot name="description">Pick a Forge site from the left to configure the managed snippet.</x-slot>
                    </x-filament::empty-state>
                </x-filament::section>
            @endif
        </section>
    </div>
</x-filament-panels::page>
