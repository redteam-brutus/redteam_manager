<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <aside class="lg:col-span-4 xl:col-span-3">
            <x-filament::section>
                <x-slot name="heading">Config files</x-slot>

                <x-slot name="afterHeader">
                    <x-filament::link wire:click="loadFiles" color="gray" size="xs">
                        Refresh
                    </x-filament::link>
                </x-slot>

                @php
                    $grouped = [];
                    foreach ($this->files as $file) {
                        $grouped[$file['group']][] = $file;
                    }
                    ksort($grouped);
                @endphp

                @if (empty($grouped))
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No config files discovered. Make sure the server is reachable and nginx is installed.
                    </p>
                @else
                    <div class="fi-nginx-file-tree space-y-4">
                        @foreach ($grouped as $group => $items)
                            <div>
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $group }}
                                </p>
                                <ul class="space-y-1">
                                    @foreach ($items as $file)
                                        <li>
                                            <button
                                                type="button"
                                                wire:click="selectFile('{{ $file['path'] }}')"
                                                @class([
                                                    'block w-full truncate rounded-md px-2 py-1 text-left font-mono text-xs transition',
                                                    'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => $selectedPath === $file['path'],
                                                    'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $selectedPath !== $file['path'],
                                                ])
                                                title="{{ $file['path'] }}"
                                            >
                                                {{ $file['relativePath'] }}
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        </aside>

        <section class="lg:col-span-8 xl:col-span-9">
            <x-filament::section>
                <x-slot name="heading">
                    @if ($selectedPath)
                        <span class="font-mono text-xs text-gray-600 dark:text-gray-400">
                            {{ $selectedPath }}
                        </span>
                        @if ($dirty)
                            <x-filament::badge color="warning" size="xs" class="ml-2">
                                unsaved
                            </x-filament::badge>
                        @endif
                    @else
                        <span class="text-sm text-gray-500 dark:text-gray-400">Select a file to preview it.</span>
                    @endif
                </x-slot>

                @if ($selectedPath && $fileContent !== null)
                    <x-slot name="afterHeader">
                        @if ($editing)
                            <x-filament::button
                                wire:click="discardDraft"
                                color="gray"
                                size="xs"
                                :disabled="! $dirty"
                            >
                                Discard
                            </x-filament::button>
                            <x-filament::button
                                wire:click="toggleEdit"
                                color="gray"
                                size="xs"
                            >
                                Cancel
                            </x-filament::button>
                        @else
                            <x-filament::button
                                wire:click="toggleEdit"
                                color="primary"
                                size="xs"
                            >
                                Edit
                            </x-filament::button>
                        @endif
                    </x-slot>
                @endif

                <div
                    wire:loading
                    wire:target="selectFile"
                    class="py-10 text-center text-sm text-gray-500 dark:text-gray-400"
                >
                    Loading…
                </div>

                <div wire:loading.remove wire:target="selectFile">
                    @if ($selectedPath && $fileContent !== null)
                        @if ($editing)
                            {{ $this->editor }}
                        @else
                            <pre class="overflow-auto whitespace-pre rounded-md bg-gray-50 p-4 font-mono text-xs leading-relaxed text-gray-800 dark:bg-gray-950 dark:text-gray-200">{{ $fileContent }}</pre>
                        @endif
                    @else
                        <x-filament::empty-state>
                            <x-slot name="heading">No file selected</x-slot>
                            <x-slot name="description">Pick a file from the left to preview it.</x-slot>
                        </x-filament::empty-state>
                    @endif
                </div>
            </x-filament::section>
        </section>
    </div>
</x-filament-panels::page>
