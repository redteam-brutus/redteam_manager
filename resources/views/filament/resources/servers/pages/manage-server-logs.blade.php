<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            @if (empty($logs))
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No log files discovered under /var/log/nginx. Make sure the server is reachable and nginx writes logs there.
                </p>
            @else
                <div class="flex flex-wrap items-center gap-3">
                    <select
                        wire:change="selectLog($event.target.value)"
                        class="fi-select-input block w-64 rounded-md border-gray-300 bg-white font-mono text-xs shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                    >
                        @foreach ($logs as $log)
                            <option value="{{ $log['path'] }}" @selected($log['path'] === $selectedPath)>
                                {{ $log['filename'] }}
                            </option>
                        @endforeach
                    </select>

                    <x-filament::button wire:click="togglePause" color="gray" size="sm">
                        {{ $paused ? 'Resume' : 'Pause' }}
                    </x-filament::button>

                    <x-filament::button wire:click="loadLogs" color="gray" size="sm">
                        Refresh files
                    </x-filament::button>

                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            wire:model.live.debounce.400ms="filter"
                            placeholder="Filter regex (client-side)"
                            class="fi-input w-64 rounded-md border-gray-300 bg-white font-mono text-xs shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                        />
                        @if (! empty($filter))
                            <x-filament::link wire:click="clearFilter" color="gray" size="xs">
                                Clear
                            </x-filament::link>
                        @endif
                    </div>

                    @if ($paused)
                        <x-filament::badge color="warning" size="xs">paused</x-filament::badge>
                    @else
                        <x-filament::badge color="success" size="xs">live</x-filament::badge>
                    @endif
                </div>
            @endif
        </x-filament::section>

        @if ($selectedPath)
            <x-filament::section>
                <x-slot name="heading">
                    <span class="font-mono text-xs text-gray-600 dark:text-gray-400">{{ $selectedPath }}</span>
                </x-slot>

                <div wire:poll.2000ms="tick">
                    @if (empty($this->filteredLines))
                        <p class="py-4 text-sm text-gray-500 dark:text-gray-400">
                            No lines to show.
                        </p>
                    @else
                        <pre class="max-h-[70vh] overflow-auto whitespace-pre-wrap break-all rounded-md bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100">@foreach ($this->filteredLines as $line){{ $line }}
@endforeach</pre>
                    @endif
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
