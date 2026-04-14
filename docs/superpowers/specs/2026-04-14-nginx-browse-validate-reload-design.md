# Nginx Browse / Validate / Reload — Foundation Spec

## Context

The first SSH spec shipped the ability to connect to servers and run commands. Operators now need to work with the Nginx config on those servers. Their goal is a structured anti-bot strategy (custom `log_format`, `map` blocks, a scenario matrix) plus Forge-deployed site configs that inject logging and `sub_filter` rules. Before we build generators and editors, we need a read-only foundation: list the files, view them, and be able to `nginx -t` and `systemctl reload nginx` safely.

This spec covers only that foundation. Later specs add a raw editor, an anti-bot strategy generator, a Forge site injector, log viewing, and a snippet library.

**Existing code this spec extends:**
- `app/Models/Server.php`, `app/Services/Ssh/SshConnectionManager.php`, `app/Services/Ssh/PhpSecLibSshSession.php`, `app/Services/Ssh/Testing/FakeSshSession.php`, `app/Filament/Resources/Servers/ServerResource.php`, `Schemas/ServerForm.php`, `Tables/ServersTable.php`.

**Key decisions (confirmed with user):**
- SSH user runs privileged commands either as root or via sudo. A new optional `use_sudo` + encrypted `sudo_password` lives on `servers` and is piped to `sudo -S` over stdin.
- File reads go through SFTP (phpseclib) rather than `cat`, so future writes can build on the same channel.
- The Nginx UI is a custom page attached to `ServerResource` at `/app/servers/{record}/nginx`.
- Nginx root is hardcoded to `/etc/nginx` for this spec; configurable per server in a later spec if needed.

**Out of scope:** raw file editing, the anti-bot generator, Forge site injection, log tail, snippet library, key management of sudo credentials beyond `encrypted` cast.

---

## Data Model

Migration `add_sudo_and_nginx_fields_to_servers_table`:

| Column | Type | Notes |
|---|---|---|
| use_sudo | boolean | default `false` |
| sudo_password | text nullable | `encrypted` cast |

`App\Models\Server`:
- Add `use_sudo`, `sudo_password` to `$fillable`.
- Casts: `use_sudo => bool`, `sudo_password => encrypted`.
- Add `sudo_password` to `$hidden`.

`ServerFactory` — default `use_sudo => false`, `sudo_password => null`. State `withSudo(?string $password = 'secret')`.

---

## SSH Layer Extensions

### Contract changes — `app/Services/Ssh/Contracts/SshSession.php`

```
public function readFile(string $path): string;

/** @return list<string> absolute paths matching the glob pattern */
public function listFiles(string $pattern): array;

public function runPrivileged(string $command, string $sudoPassword, int $timeoutSeconds = 10): CommandResult;
```

### `PhpSecLibSshSession`
- Hold `?\phpseclib3\Net\SFTP $sftp = null` lazily opened against the same host/port/user with the same authenticated private key.
- `readFile($path)` → `$this->sftp()->get($path)`. On failure → `SshCommandException`.
- `listFiles($pattern)` → split pattern into `dirname` + glob; `$this->sftp()->nlist($dir)`; filter via `fnmatch()`. Returns absolute paths.
- `runPrivileged($command, $sudoPassword, $timeout)` → build remote shell invocation:
  `printf '%s\n' '<escaped-password>' | sudo -S -p '' -- /bin/sh -c '<escaped-command>'`
  - Single quotes inside password/command escaped via `str_replace("'", "'\''", $value)`.
  - Password reaches `sudo -S` on stdin; never appears in argv of any remote process.
- `disconnect()` also disconnects the SFTP handle when set.

### `FakeSshClient` / `FakeSshSession`
- New fluent setters:
  - `withFile(string $path, string $content): self`
  - `withListing(string $pattern, array $paths): self`
  - `shouldReturnForCommand(string $commandMatch, int $exitCode, string $stdout = '', string $stderr = ''): self` (optional keyed responses)
- New recorded state: `$privilegedCommands: list<array{command: string, password: string}>` and `$lastSudoPassword: ?string`.
- `readFile` throws a canned `SshCommandException` if no mapping set (explicit failure in tests).

### `SshConnectionManager` additions

```
public function readFile(Server $server, string $path): string;

/** @return list<string> */
public function listFiles(Server $server, string $pattern): array;

public function runPrivileged(Server $server, string $command, int $timeoutSeconds = 10): CommandResult;
```

- All three open an authenticated session, verify TOFU fingerprint, perform the action, disconnect in `finally`.
- `runPrivileged` routes:
  - `$server->use_sudo === true` and `$server->sudo_password === null` → throw `SshAuthException('Sudo password not set on server.')`.
  - `$server->use_sudo === true` → call `$session->runPrivileged($command, $server->sudo_password, $timeout)`.
  - `$server->use_sudo === false` → call `$session->run($command, $timeout)`. The assumption is documented in the UI ("the SSH user already has privilege").

---

## Nginx Manager Service

Namespace: `App\Services\Nginx`.

### DTOs

```
final readonly class NginxFile {
    public function __construct(
        public string $path,         // absolute
        public string $group,        // 'main' | 'conf.d' | 'forge:<siteId>' | 'forge:<siteId>/server'
        public string $relativePath, // relative to /etc/nginx
    ) {}
}

final readonly class NginxTestResult {
    public function __construct(public bool $ok, public string $output) {}
}
```

### `NginxManager`

```
public function __construct(
    private SshConnectionManager $ssh,
    private string $nginxRoot = '/etc/nginx',
) {}

/** @return list<NginxFile> */
public function listFiles(Server $server): array;

public function readFile(Server $server, string $path): string;

public function validate(Server $server): NginxTestResult;

public function reload(Server $server): NginxTestResult;
```

`listFiles($server)` patterns and grouping:
1. `{root}/nginx.conf` → `main`.
2. `{root}/conf.d/*.conf` → `conf.d`.
3. `{root}/forge-conf/*/site.conf` → `forge:{siteId}`, where `{siteId}` is the directory name.
4. `{root}/forge-conf/*/server/*` → `forge:{siteId}/server`.

Return sorted by `(group, path)`.

`readFile($server, $path)` guards:
- Reject empty, relative, or path-traversal strings. Normalise to an absolute canonical form by rejecting any segment equal to `..`.
- Reject unless `str_starts_with($normalised, $this->nginxRoot . '/')` or equals `{root}/nginx.conf`.

`validate($server)`:
- Runs `nginx -t 2>&1` via `runPrivileged`.
- Returns `NginxTestResult(ok: $result->exitCode === 0, output: $result->stdout)`.

`reload($server)`:
- Calls `validate`. If `! $result->ok` → return the failing result; do not reload.
- Else runs `systemctl reload nginx` via `runPrivileged`.
- Returns `NginxTestResult(ok: $result->exitCode === 0, output: $result->stdout . $result->stderr)`.

---

## Filament UI

### Server form additions — `app/Filament/Resources/Servers/Schemas/ServerForm.php`

```
Toggle::make('use_sudo')
    ->label('Use sudo for privileged commands')
    ->live(),

TextInput::make('sudo_password')
    ->label('Sudo password')
    ->password()
    ->revealable()
    ->visible(fn (Get $get) => $get('use_sudo') === true)
    ->required(fn (Get $get, string $operation) => $operation === 'create' && $get('use_sudo') === true)
    ->dehydrated(fn (?string $state): bool => filled($state))
    ->helperText('Stored encrypted. Leave blank on edit to keep the existing password.'),
```

On edit, `EditServer::mutateFormDataBeforeSave` already exists for ssh keys; leave password alone when blank (the `dehydrated` closure handles this).

### Servers table — new row action

Add a `Nginx` row action linking to the new page URL, placed after `TestConnectionAction`.

### Custom page — `ManageServerNginx`

Generate via `php artisan make:filament-page ManageServerNginx --resource=ServerResource --type=custom`.

Route slug: `nginx`. Full URL: `/app/servers/{record}/nginx`.

Properties:
- `public Server $record;` (bound by Filament's `HasRecord`).
- `public ?string $selectedPath = null;`
- `public ?string $fileContent = null;`
- `public bool $loadingContent = false;`
- `public array $files = [];` (flat list of `['path' => ..., 'group' => ..., 'relativePath' => ...]`).

Methods:
- `mount()` → `$this->loadFiles();`
- `loadFiles()` → wraps `app(NginxManager::class)->listFiles($this->record)`; on `SshException` show red notification and leave list empty.
- `selectFile(string $path)` → assigns path, sets `$loadingContent = true`, calls `readFile`, updates `$fileContent`; errors → red notification, `$fileContent = null`.
- `validate()` → `NginxManager::validate($this->record)`; success notification with short line, failure notification shows full output in the body.
- `reload()` — header action with confirmation modal; runs `NginxManager::reload`; green on success, red on failure (never reloads if validate fails).

Blade view at `resources/views/filament/resources/servers/pages/manage-server-nginx.blade.php`:
- Two-pane Tailwind layout: grouped file list on the left, readonly `<pre class="font-mono whitespace-pre overflow-auto">` on the right with `x-loading` skeleton while loading.
- Groups rendered with a label and ordered list; clicking calls `wire:click="selectFile('{{ $file['path'] }}')"`.
- Header actions block wraps `Validate` and `Reload` Filament `Action` objects.

Register the page in `ServerResource::getPages()`:

```
'nginx' => ManageServerNginx::route('/{record}/nginx'),
```

---

## Security

- `sudo_password`: `encrypted` cast, in `$hidden`, never logged.
- Command composition: single quotes in password and command escaped; password delivered on stdin, not via argv.
- Path traversal: `NginxManager::readFile` rejects any path that is not an absolute path under `/etc/nginx`. UI only offers paths returned from `listFiles`, but the guard protects against a tampered Livewire payload.
- `reload()` refuses to reload if `nginx -t` fails, so broken configs cannot be hot-loaded.
- No writes in this spec.
- Filament authenticated-user gate is sufficient; policies land with the roles spec.

---

## Files to Create / Modify

**Create**
- `database/migrations/*_add_sudo_and_nginx_fields_to_servers_table.php`
- `app/Services/Nginx/Dto/NginxFile.php`
- `app/Services/Nginx/Dto/NginxTestResult.php`
- `app/Services/Nginx/NginxManager.php`
- `app/Filament/Resources/Servers/Pages/ManageServerNginx.php`
- `resources/views/filament/resources/servers/pages/manage-server-nginx.blade.php`
- `tests/Feature/Nginx/NginxManagerTest.php`
- `tests/Feature/Filament/ManageServerNginxTest.php`

**Modify**
- `app/Models/Server.php`
- `database/factories/ServerFactory.php`
- `app/Services/Ssh/Contracts/SshSession.php`
- `app/Services/Ssh/PhpSecLibSshSession.php`
- `app/Services/Ssh/Testing/FakeSshSession.php`
- `app/Services/Ssh/Testing/FakeSshClient.php`
- `app/Services/Ssh/SshConnectionManager.php`
- `app/Filament/Resources/Servers/ServerResource.php`
- `app/Filament/Resources/Servers/Schemas/ServerForm.php`
- `app/Filament/Resources/Servers/Tables/ServersTable.php`
- `tests/Feature/Filament/ServerResourceTest.php` (new cases for sudo fields)

---

## Tests (Pest)

`tests/Feature/Nginx/NginxManagerTest.php`:
- `listFiles` aggregates main / conf.d / forge site.conf / forge server snippets and groups them correctly.
- `readFile` returns content from the fake SFTP mapping.
- `readFile` rejects a path outside `/etc/nginx` (throws `InvalidArgumentException`).
- `readFile` rejects a path containing `..`.
- `validate` returns `ok=true` when exit code is 0; `ok=false` with merged output when non-zero.
- `reload` short-circuits to failure when validate fails (no reload command sent).
- `reload` runs `systemctl reload nginx` after successful validate.
- Commands go through `runPrivileged` and record the sudo password when `use_sudo` is true.
- Commands go through plain `run()` when `use_sudo` is false.

`tests/Feature/Filament/ManageServerNginxTest.php`:
- Mount loads file list (Livewire).
- Selecting a file loads content.
- `validate` Filament action notifies success.
- `validate` Filament action notifies failure with `nginx -t` output.
- `reload` action with validation failure does not call reload and notifies failure.

`tests/Feature/Filament/ServerResourceTest.php` extensions:
- Toggling `use_sudo` reveals `sudo_password` field and saves it encrypted.
- Editing with a blank `sudo_password` preserves the existing one.
- Raw DB row for `sudo_password` is ciphertext.

---

## Verification

1. `php artisan migrate` — new columns on `servers`.
2. `php artisan test --compact` — all green (existing + new).
3. `vendor/bin/pint --dirty --format agent` — no diffs.
4. On a real server: log into `/app`, edit a server, set `use_sudo = true`, paste the sudo password. Save.
5. From the Servers table, click **Nginx** on that row.
6. Expect file tree with groups `main`, `conf.d`, `forge:<siteId>`, `forge:<siteId>/server`. Click a file → content renders.
7. Click **Validate config** → green notification "Config valid" (shows the usual nginx -t success lines).
8. SSH to the server, introduce a syntax error in a managed file, click **Validate** again → red notification, body contains the `nginx: [emerg]` line.
9. Click **Reload nginx** while the config is broken → red notification, nginx is NOT reloaded.
10. Fix the file; click **Reload** → green notification, service reloads (confirm via `systemctl status nginx` from the terminal).
11. `storage/logs/laravel.log` — no sudo password, no file contents present.
