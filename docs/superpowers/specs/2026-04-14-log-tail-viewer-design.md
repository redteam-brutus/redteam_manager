# Log Tail Viewer

## Context

Operators need a live view of nginx logs while tuning anti-bot gates, debugging injection, and watching fbclid traffic. This spec adds a per-server page that discovers log files under `/var/log/nginx/` and polls `tail -n 500 <path>` every 2 seconds over SSH. No long-lived SSH connections, no SSE plumbing — polling is simple, reliable, and fits the existing ephemeral SSH pattern.

---

## Architecture

```
ServerResource
  └── ManageServerLogs (Filament custom page, /{record}/logs)
         ↓
      LogViewer
          ├── list(Server) : LogFile[]
          └── tail(Server, path, lines = 500) : string
         ↓
      SshConnectionManager::run / runPrivileged   (ls, tail)
```

**Principles:**
- Ephemeral SSH: each tick opens a fresh connection, runs `tail`, closes.
- Whitelist path guard: caller can only tail under `/var/log/nginx/` with `.log` / `.log.N` suffix. Defense against URL-manipulated path values.
- Sudo-aware: `/var/log/nginx/*` is typically root-owned, so `tail` goes through `runPrivileged` when `server->use_sudo`.

---

## Data Model

### DTO — `app/Services/Nginx/Logs/Dto/LogFile.php`

```php
final readonly class LogFile
{
    public function __construct(
        public string $path,         // /var/log/nginx/access.log
        public string $filename,     // access.log
    ) {}
}
```

### Livewire state on `ManageServerLogs`

```php
/** @var list<array{path: string, filename: string}> */
public array $logs = [];

public ?string $selectedPath = null;

/** @var list<string> */
public array $lines = [];

public bool $paused = false;

public ?string $filter = null;   // client-side regex, not applied server-side
```

---

## Service Layer

### `App\Services\Nginx\Logs\LogViewer`

```php
public function __construct(private readonly SshConnectionManager $ssh) {}

/**
 * @return list<LogFile>
 */
public function list(Server $server): array;

public function tail(Server $server, string $path, int $lines = 500): string;
```

**`list` implementation:**
- Runs `ls -1 /var/log/nginx/*.log /var/log/nginx/*.log.1 2>/dev/null`.
- Splits lines, trims, filters empties.
- Maps each to a `LogFile` (`path`, `filename`).
- Non-sudo: `ls` usually works because the directory is world-executable even when files are root-owned.

**`tail` implementation:**
- `assertWithinLogRoot($path)` first: regex `^/var/log/nginx/[A-Za-z0-9._-]+(\.log|\.log\.[0-9]+)$`. Reject anything else with `InvalidArgumentException`.
- Clamp `lines` to `[1, 2000]`.
- Command: `tail -n <lines> <path>` — `escapeshellarg` the path.
- Route through `runPrivileged` when `server->use_sudo`, else `run`.
- Return `$result->stdout` (trimmed? no — keep raw including trailing newline so the blade `<pre>` shows it faithfully).

---

## Filament Layer

### New custom page

Route: `/app/servers/{record}/logs`.

Register in `ServerResource::getPages()` as `'logs' => ManageServerLogs::route('/{record}/logs')`.

Add row action in `ServersTable`: `Action::make('logs')->label('Logs')->icon(Heroicon::OutlinedDocumentText)->url(fn ($record) => ManageServerLogs::getUrl(['record' => $record]))`.

### Page behaviour

- `mount()` → resolve server, call `LogViewer::list`, store results in `logs`. If `logs` is non-empty, auto-select the first one and fetch its initial tail.
- `selectLog(string $path)` → set `selectedPath`, immediately `refresh()`.
- `tick()` → if `paused === false` and `selectedPath !== null`, call `LogViewer::tail`, split on `\n`, take last 500, store in `lines`.
- `togglePause()` → flip `paused`.
- `clearFilter()` → set `filter = null`.

### Blade

```
<x-filament-panels::page>
    <x-filament::section>
        <div class="flex items-center gap-3">
            <select wire:change="selectLog($event.target.value)">
                @foreach ($logs as $log) ... @endforeach
            </select>
            <x-filament::button wire:click="togglePause" color="gray" size="sm">
                {{ $paused ? 'Resume' : 'Pause' }}
            </x-filament::button>
            <input wire:model.live.debounce.400ms="filter" placeholder="Filter regex (client-side)" class="…" />
        </div>
    </x-filament::section>

    <x-filament::section>
        <div wire:poll.2000ms="tick" class="relative">
            <pre class="max-h-[70vh] overflow-auto whitespace-pre-wrap rounded-md bg-gray-950 p-4 font-mono text-xs text-gray-100">@foreach ($this->filteredLines as $line){{ $line }}
@endforeach</pre>
        </div>
    </x-filament::section>
</x-filament-panels::page>
```

`filteredLines` computed property: applies the user's regex to `lines`. Invalid regex → show the raw lines.

Autoscroll via `@keydown.window`/alpine small snippet or simply `scroll-behavior: smooth` + CSS flex-column-reverse trick — detail left to implementation.

### Going-forward rule compliance

Filament `Section`, `Button`, `Action`, and `Notification` for interactive elements. Custom Tailwind only on the log viewport (a `<pre>` container), matching the preview boxes used on other pages.

---

## Security

- `assertWithinLogRoot` whitelist regex: `^/var/log/nginx/[A-Za-z0-9._-]+(\.log|\.log\.[0-9]+)$`.
- `escapeshellarg` on the path. No user-string interpolation into shell beyond that.
- `tail -n` clamped to `[1, 2000]`.
- Sudo password never logged. Reuses existing `runPrivileged` plumbing.
- Filament auth gate remains the authorization layer until the roles spec.

---

## Tests

### `tests/Feature/Nginx/Logs/LogViewerTest.php`

- `list` parses `ls` output into `LogFile[]`
- `tail` runs non-sudo when `use_sudo=false`; command matches `tail -n 500 '/var/log/nginx/access.log'`
- `tail` runs privileged when `use_sudo=true`; `lastSudoPassword` set
- `tail` rejects `/etc/passwd` with `InvalidArgumentException`
- `tail` rejects `/var/log/nginx/../../etc/passwd`
- `tail` clamps a huge `lines` value down to `2000`

### `tests/Feature/Filament/ManageServerLogsTest.php`

- `mount` populates `logs` from the ls output
- `mount` auto-selects the first log and fetches an initial tail
- `selectLog` refreshes `lines`
- `tick` while `paused=true` is a no-op — no new SSH command recorded
- `tick` while `paused=false` re-fetches and updates `lines`

---

## Verification (real server)

1. `php artisan test --compact` green.
2. `vendor/bin/pint --dirty --format agent` clean.
3. `npm run build`.
4. Open `/app/servers/<id>/logs`. Dropdown lists available nginx log files. First one is selected, last 500 lines displayed.
5. `curl https://<site>/` a few times — new entries appear within 2 s.
6. Pause → no new lines. Resume → they start appearing again.
7. Enter `fbclid` as the filter — only lines containing `fbclid` remain visible. Clear filter → all lines return.
8. Attempt to switch to `/etc/passwd` via the URL (manual wire:call) → request is rejected by `assertWithinLogRoot`; no file contents leak.

---

## Files to Create / Modify

**New — DTO + service**
- `app/Services/Nginx/Logs/Dto/LogFile.php`
- `app/Services/Nginx/Logs/LogViewer.php`

**New — Filament**
- `app/Filament/Resources/Servers/Pages/ManageServerLogs.php`
- `resources/views/filament/resources/servers/pages/manage-server-logs.blade.php`

**Modify — Filament**
- `app/Filament/Resources/Servers/ServerResource.php` — register `logs` page.
- `app/Filament/Resources/Servers/Tables/ServersTable.php` — add **Logs** row action.

**New — tests**
- `tests/Feature/Nginx/Logs/LogViewerTest.php`
- `tests/Feature/Filament/ManageServerLogsTest.php`

---

## Existing Code to Reuse

- `App\Services\Ssh\SshConnectionManager::run` and `runPrivileged` — transport.
- `App\Services\Ssh\Testing\FakeSshClient` — `shouldReturn` + `shouldReturnForCommand` for test fixtures.
- `App\Filament\Resources\Servers\Pages\ManageServerNginx` — two-pane layout reference for blade/icon/notification patterns.
