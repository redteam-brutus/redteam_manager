<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->editor }}

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Rendered preview</x-slot>
            <x-slot name="description">What will be written to /etc/nginx/conf.d/redteam-antibot.conf</x-slot>

            @if ($renderedPreview === null || $renderedPreview === '')
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Nothing to write — with all lists empty, Save will remove the managed file.
                </p>
            @else
                <pre class="overflow-auto whitespace-pre rounded-md bg-gray-50 p-4 font-mono text-xs leading-relaxed text-gray-800 dark:bg-gray-950 dark:text-gray-200">{{ $renderedPreview }}</pre>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
