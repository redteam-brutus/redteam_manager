<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <aside class="lg:col-span-4 xl:col-span-3">
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-white/10">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Config files</h2>
                    <button
                        type="button"
                        wire:click="loadFiles"
                        class="text-xs font-medium text-primary-600 hover:text-primary-500"
                    >
                        Refresh
                    </button>
                </div>

                @php
                    $grouped = [];
                    foreach ($this->files as $file) {
                        $grouped[$file['group']][] = $file;
                    }
                    ksort($grouped);
                @endphp

                @if (empty($grouped))
                    <p class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">
                        No config files discovered. Make sure the server is reachable and nginx is installed.
                    </p>
                @else
                    <div class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($grouped as $group => $items)
                            <div class="px-4 py-3">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $group }}
                                </p>
                                <ul class="space-y-1">
                                    @foreach ($items as $file)
                                        <li>
                                            <button
                                                type="button"
                                                wire:click="selectFile(@js($file['path']))"
                                                @class([
                                                    'w-full truncate rounded-md px-2 py-1.5 text-left font-mono text-xs',
                                                    'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => $selectedPath === $file['path'],
                                                    'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $selectedPath !== $file['path'],
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
            </div>
        </aside>

        <section class="lg:col-span-8 xl:col-span-9">
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-white/10">
                    @if ($selectedPath)
                        <p class="font-mono text-xs text-gray-600 dark:text-gray-400">
                            {{ $selectedPath }}
                            @if ($dirty)
                                <span class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">
                                    unsaved
                                </span>
                            @endif
                        </p>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">Select a file to preview it.</p>
                    @endif

                    @if ($selectedPath && $fileContent !== null)
                        <div class="flex items-center gap-2">
                            @if ($editing)
                                <button
                                    type="button"
                                    wire:click="discardDraft"
                                    @class([
                                        'rounded-md px-2.5 py-1 text-xs font-medium',
                                        'text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white',
                                    ])
                                    @disabled(! $dirty)
                                >
                                    Discard
                                </button>
                                <button
                                    type="button"
                                    wire:click="toggleEdit"
                                    class="rounded-md px-2.5 py-1 text-xs font-medium text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white"
                                >
                                    Cancel
                                </button>
                            @else
                                <button
                                    type="button"
                                    wire:click="toggleEdit"
                                    class="rounded-md bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 hover:bg-primary-100 dark:bg-primary-500/10 dark:text-primary-300 dark:hover:bg-primary-500/20"
                                >
                                    Edit
                                </button>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="relative">
                    <div
                        wire:loading
                        wire:target="selectFile"
                        class="absolute inset-0 flex items-center justify-center bg-white/60 text-sm text-gray-500 dark:bg-gray-900/60"
                    >
                        Loading…
                    </div>

                    @if ($selectedPath && $fileContent !== null)
                        @if ($editing)
                            <textarea
                                wire:model.live.debounce.500ms="draftContent"
                                rows="28"
                                spellcheck="false"
                                class="block w-full resize-none border-0 bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100 outline-none focus:ring-0"
                            ></textarea>
                        @else
                            <pre class="overflow-auto whitespace-pre p-4 font-mono text-xs leading-relaxed text-gray-800 dark:text-gray-200">{{ $fileContent }}</pre>
                        @endif
                    @else
                        <div class="px-4 py-10 text-center text-sm text-gray-400">
                            No file selected.
                        </div>
                    @endif
                </div>
            </div>
        </section>
    </div>
</x-filament-panels::page>
