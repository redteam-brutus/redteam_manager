<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <aside class="lg:col-span-4 xl:col-span-3">
            <x-filament::section>
                <x-slot name="heading">Config files</x-slot>

                <x-slot name="headerActions">
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
                                            <x-filament::link
                                                tag="button"
                                                wire:click="selectFile(@js($file['path']))"
                                                :color="$selectedPath === $file['path'] ? 'primary' : 'gray'"
                                                size="xs"
                                                class="w-full truncate font-mono"
                                            >
                                                {{ $file['relativePath'] }}
                                            </x-filament::link>
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
                    <x-slot name="headerActions">
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
