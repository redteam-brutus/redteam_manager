@php($diff = (string) ($diff ?? ''))

@if ($diff === '')
    <p class="text-sm text-gray-500 dark:text-gray-400">No changes detected.</p>
@else
    <pre class="max-h-96 overflow-auto rounded-md bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100">@foreach (preg_split('/\r?\n/', $diff) as $line)@php($color = match (true) {
    str_starts_with($line, '+++') || str_starts_with($line, '---') => 'text-gray-400',
    str_starts_with($line, '@@') => 'text-cyan-300',
    str_starts_with($line, '+') => 'text-green-300',
    str_starts_with($line, '-') => 'text-rose-300',
    default => 'text-gray-200',
})<span class="block {{ $color }}">{{ $line === '' ? ' ' : $line }}</span>@endforeach</pre>
@endif
