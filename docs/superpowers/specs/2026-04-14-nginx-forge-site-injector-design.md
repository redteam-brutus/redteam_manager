# Forge Site Injector (Fully-Managed Files)

## Context

Forge-provisioned servers place each site's nginx config at `/etc/nginx/forge-conf/<siteId>/site.conf` and load extra server-block snippets via `include forge-conf/<siteId>/server/*;`. The first NGINX specs (browse/validate/reload + raw editor) already list those files and let the operator hand-edit them.

This spec adds a higher-level, per-Forge-site "injector" screen for two recurring toggles:

1. **Analytics injection** — an in-page `<script>` injected via `sub_filter` at the end of `<head>` on responses that include `?fbclid=…`.
2. **Conditional access log** — a `map $arg_fbclid $log_specific_fbclid { default 0; ~.+ 1; }` + a matching `access_log /var/log/nginx/<site>-fbclid.log combined if=$log_specific_fbclid;` so only fbclid traffic is logged to a separate file.

**Key decision (revised with user):** Do NOT splice markers into Forge's `site.conf`. Instead, drop a fully-managed file at `/etc/nginx/forge-conf/<siteId>/server/redteam-analytics.conf`. Forge's existing `include forge-conf/<siteId>/server/*;` loads it automatically. Editing our own file never touches Forge's hand-written config and survives Forge redeploys as long as the include is preserved.

The same pattern scales to a future app-wide anti-bot strategy (`/etc/nginx/conf.d/redteam-antibot.conf`) without any new mutation logic.

---

## Architecture

```
ServerResource
  └── ManageForgeSites (Filament custom page, /{record}/forge-sites)
            ↓
      ForgeSiteRegistry
          ├── list(Server) : ForgeSite[]           -- discovery
          ├── find(Server, siteId) : ForgeSite     -- detail + parsed settings
          ├── save(Server, siteId, settings)       -- render + writeManagedFile
          └── disable(Server, siteId)              -- deleteManagedFile
            ↓
      ForgeSiteSettingsParser  ←── parses existing managed file
      ForgeSiteSettingsRenderer ──→ produces canonical managed block
            ↓
      NginxManager
          ├── writeManagedFile(Server, path, content) : NginxSaveResult
          └── deleteManagedFile(Server, path) : NginxSaveResult
            ↓
      SshConnectionManager (existing) — sudo-aware SFTP + shell
```

**Principles:**
- Managed files are **owned by this app**. Parser/renderer round-trip; edits are idempotent.
- Reuse the existing sudo-aware write flow from `NginxManager::saveFile` — stage in `/tmp`, `sudo cp -p` backup (only if target exists), `sudo mv` install, `nginx -t`, rollback on failure.
- Discovery rides on `NginxManager::listFiles` output, which already enumerates `forge-conf/<id>/site.conf` and `forge-conf/<id>/server/*`. No new SSH probes.

---

## Data Model

### DTOs — `app/Services/Nginx/Forge/Dto/`

```php
final readonly class ForgeSite
{
    public function __construct(
        public string $siteId,                 // e.g. "3075741"
        public string $siteConfPath,           // /etc/nginx/forge-conf/3075741/site.conf
        public string $managedPath,            // /etc/nginx/forge-conf/3075741/server/redteam-analytics.conf
        public bool $hasManaged,               // managed file currently exists
        public ForgeSiteSettings $settings,    // parsed from managed file, or defaults
    ) {}
}

final readonly class ForgeSiteSettings
{
    public function __construct(
        public bool $analyticsEnabled = false,
        public string $trackingTag = '</head>',   // whitelist: </head>|</body>|<head>|<body>
        public string $scriptBody = '',           // inline <script>...</script> payload; validated, not interpolated to shell
        public bool $conditionalAccessLog = false,
        public string $accessLogPath = '',        // e.g. /var/log/nginx/example.com-fbclid.log
    ) {}
}
```

Neither DTO is a Laravel model — they exist only in memory. Settings are derived from file content on every load.

### Managed file format

Canonical content produced by `ForgeSiteSettingsRenderer`:

```
# Managed by redteam-manager. Do not edit by hand.
# Site: <siteId>

# --- conditional access log (optional) ---
map $arg_fbclid $log_specific_fbclid {
    default 0;
    ~.+     1;
}
access_log /var/log/nginx/<site>-fbclid.log combined if=$log_specific_fbclid;

# --- analytics injection (optional) ---
sub_filter_once on;
sub_filter '</head>' '<script>/* ... */</script></head>';
```

Blocks are emitted only when their toggle is on. If both are off, the file is deleted.

### Parser contract

`ForgeSiteSettingsParser::parse(string $content): ForgeSiteSettings`

- Recognises the `map $arg_fbclid … { default 0; ~.+ 1; }` block + paired `access_log … if=$log_specific_fbclid;` as conditional-access-log on.
- Recognises `sub_filter_once on;` + a `sub_filter '<tag>' '<body>';` line as analytics on, extracts tag + script body.
- Ignores stray whitespace and comments.
- Unknown content → return defaults (we overwrite on next save — the file is ours).

---

## SSH / NGINX Layer

### `NginxManager` extensions

Two new public methods, both delegating to the existing sudo-aware swap flow:

```php
public function writeManagedFile(Server $server, string $path, string $content): NginxSaveResult
public function deleteManagedFile(Server $server, string $path): NginxSaveResult
```

- Both call `assertManagedPath($path)` — requires absolute path under `/etc/nginx/forge-conf/<id>/server/redteam-*.conf` OR `/etc/nginx/conf.d/redteam-*.conf`, with `<id>` matching `^[0-9]+$`.
- `writeManagedFile` stages in `/tmp/redteam-<rand>.tmp`, takes a `*.bak.<ts>` backup only if the target already exists, installs via `sudo mv`, runs `nginx -t`, on failure either restores the backup or removes the freshly-written file.
- `deleteManagedFile` takes a `.bak.<ts>` backup, `sudo rm -f`s the file, runs `nginx -t`, on failure restores the backup.
- Both prune `.bak.*` beyond the newest 10, reusing `pruneBackups`.

No change to the `saveFile` path — the raw editor keeps its own flow.

### Registry — `App\Services\Nginx\Forge\ForgeSiteRegistry`

```php
public function list(Server $server): array                           // ForgeSite[]
public function find(Server $server, string $siteId): ForgeSite
public function save(Server $server, string $siteId, ForgeSiteSettings $settings): NginxSaveResult
public function disable(Server $server, string $siteId): NginxSaveResult
```

- `list`: calls `NginxManager::listFiles`, keeps only `forge:<id>` / `forge:<id>/server` groups, dedupes by `<id>`, and for each id probes `fileExists` on the managed path to set `hasManaged`. Settings defaulted on list; parse on demand in `find`.
- `find`: loads the managed file via `NginxManager::readFile` if present, parses. Otherwise returns defaults.
- `save`: renders via `ForgeSiteSettingsRenderer`. If output is empty (everything toggled off), routes to `disable`. Otherwise `NginxManager::writeManagedFile`.
- `disable`: `NginxManager::deleteManagedFile` (idempotent — missing file returns `saved` with output `'Already disabled.'`).

---

## Filament Layer

### New custom page

Create with: `php artisan make:filament-page ManageForgeSites --resource=ServerResource --type=custom --no-interaction`

Route: `/app/servers/{record}/forge-sites` (registered in `ServerResource::getPages()`).

**Page state (Livewire properties):**

```php
public ?string $selectedSiteId = null;
public ?array $data = null;              // statePath for the Filament form
public array $sites = [];                // cached list for the left pane
public ?string $renderedPreview = null;  // live preview of what will be written
public bool $hasManaged = false;
```

**Form schema** (Filament `Schema` with `statePath('data')`):

- `Section::make('Analytics injection')`
  - `Toggle::make('analyticsEnabled')->live(debounce: 400)`
  - `Select::make('trackingTag')->options(['</head>','</body>','<head>','<body>'])->default('</head>')`
  - `Textarea::make('scriptBody')->rows(6)->helperText('Inline <script>…</script> HTML')`
- `Section::make('Conditional access log')`
  - `Toggle::make('conditionalAccessLog')->live(debounce: 400)`
  - `TextInput::make('accessLogPath')->placeholder('/var/log/nginx/example.com-fbclid.log')`

Each `->live` update recomputes `renderedPreview` via the renderer so the right pane shows what's about to land on disk.

**Header actions:**

- `Save` — confirms with a diff (current file on disk vs rendered), calls `registry->save`.
- `Disable` — visible only when `hasManaged`, confirms, calls `registry->disable`.
- `Reload nginx` — same pattern as the raw editor page.

**Layout (blade, `resources/views/filament/resources/servers/pages/manage-forge-sites.blade.php`):**

Two-pane, same structure as `manage-server-nginx.blade.php`:

- Left `x-filament::section`: list of Forge sites grouped by id, managed-status badge next to ones with a managed file present.
- Right `x-filament::section`: when a site is selected, the Filament form + a collapsed `CodeEditor` preview (read-only) showing `$renderedPreview`.

Row + header action in `ServersTable` alongside **Nginx**: `Action::make('forge')->label('Forge sites')->icon(Heroicon::OutlinedCodeBracket)->url(fn (Server $r) => ManageForgeSites::getUrl(['record' => $r]))`.

### Going-forward rule compliance

Every interactive element uses Filament components (`Section`, `Toggle`, `Select`, `Textarea`, `TextInput`, `CodeEditor`, `Action`, `Notification`). Custom Tailwind only in the left-pane site list, matching the existing raw-editor page.

---

## Security

- **Managed-path guard.** `NginxManager::assertManagedPath` refuses anything that doesn't match
  `^/etc/nginx/(forge-conf/[0-9]+/server|conf\.d)/redteam-[a-z0-9-]+\.conf$`.
  Path traversal (`/../`) already rejected by `assertWithinRoot`.
- **Site id shape.** `ForgeSiteRegistry` validates `siteId` matches `^[0-9]+$` before building any path.
- **No shell interpolation of content.** Managed-file bytes are produced entirely by `ForgeSiteSettingsRenderer` and written via SFTP to `/tmp`, then moved with `sudo mv` — no `echo`/`cat` redirects.
- **Tracking-tag whitelist.** Only `</head>`, `</body>`, `<head>`, `<body>` are accepted. Anything else fails validation.
- **Script body treated as file bytes only** — never interpolated into a shell command, but it *is* rendered inside an nginx `sub_filter` string, so single quotes in the body must be escaped to `'\''` by the renderer.
- Sudo password reused through the existing `runPrivileged` path; never logged.
- Filament authenticated-user gate remains the only authorization layer until the roles spec.

---

## Files to Create / Modify

**New — DTOs**
- `app/Services/Nginx/Forge/Dto/ForgeSite.php`
- `app/Services/Nginx/Forge/Dto/ForgeSiteSettings.php`

**New — services**
- `app/Services/Nginx/Forge/ForgeSiteRegistry.php`
- `app/Services/Nginx/Forge/ForgeSiteSettingsParser.php`
- `app/Services/Nginx/Forge/ForgeSiteSettingsRenderer.php`

**Modify — `app/Services/Nginx/NginxManager.php`**
- Add `writeManagedFile`, `deleteManagedFile`, `assertManagedPath` (private).

**New — Filament**
- `app/Filament/Resources/Servers/Pages/ManageForgeSites.php`
- `resources/views/filament/resources/servers/pages/manage-forge-sites.blade.php`

**Modify — Filament**
- `app/Filament/Resources/Servers/ServerResource.php` — register `forge-sites` page.
- `app/Filament/Resources/Servers/Tables/ServersTable.php` — add row / header action linking to the new page.

**New — tests**
- `tests/Feature/Nginx/Forge/ForgeSiteRegistryTest.php`
- `tests/Feature/Filament/ManageForgeSitesTest.php`

---

## Tests

### `ForgeSiteRegistryTest`

- **list discovers forge sites** — seed fake listings for `find … forge-conf … site.conf` + `find … forge-conf … server` commands; assert returned `ForgeSite[]` ids and `hasManaged` flags.
- **find returns defaults when no managed file** — assert `settings->analyticsEnabled === false`.
- **find parses an existing managed file** — seed file bytes, expect `analyticsEnabled=true`, `conditionalAccessLog=true`, `trackingTag='</head>'`, script body extracted.
- **renderer round-trips** — feed parser output back through renderer → parser, assert equal settings.
- **renderer emits nothing when all toggles off** — empty string.
- **save writes the managed file** — assert sudo `mv` lands at `forge-conf/<id>/server/redteam-analytics.conf`, `nginx -t` ran, returns `saved`.
- **save with brand-new file and invalid nginx** — rollback removes the new file, returns `invalid_config`.
- **save with existing file and invalid nginx** — rollback restores `.bak.*`, file bytes unchanged.
- **disable removes the managed file** — when `analyticsEnabled=false && conditionalAccessLog=false`, save routes to disable; `sudo rm -f` recorded, returns `saved`.
- **rejects non-managed paths** — calling `writeManagedFile` on `/etc/nginx/nginx.conf` throws `InvalidArgumentException`.
- **rejects non-numeric site id** — registry validates `siteId`.

### `ManageForgeSitesTest`

- **mount loads the site list** — assert `sites` populated from fake listings.
- **selectSite hydrates form state** — `data.analyticsEnabled` reflects managed file.
- **saveSite succeeds** — Livewire call with toggled-on state → success notification, `hasManaged=true`.
- **saveSite with all toggles off disables** — file deleted, success notification, `hasManaged=false`.
- **invalid nginx config** — fake returns exit 1 from `nginx -t`; assert danger notification, rollback fired.
- **rejects navigation without auth** — covered by existing `ServerResource` tests; smoke-check the new route exists.

Factories, fake SSH client, and DB setup follow existing `ManageServerNginxTest` patterns.

---

## Verification (real server)

1. `php artisan test --compact` — green.
2. `vendor/bin/pint --dirty --format agent` — no diffs.
3. Log into `/app`, open a server that already lists Forge sites via the raw editor.
4. Click **Forge sites**, select a site. Toggle analytics on, set script body, Save.
   - Managed file appears at `/etc/nginx/forge-conf/<id>/server/redteam-analytics.conf`.
   - `nginx -t` passes, reload nginx, curl `<host>/?fbclid=x` → injected script present in HTML.
5. Toggle conditional access log on, set `/var/log/nginx/<site>-fbclid.log`, Save.
   - Managed file now includes `map` + `access_log … if=$log_specific_fbclid;`.
   - Tail log, only fbclid requests hit it.
6. Toggle both off, Save → managed file removed, `nginx -t` passes.
7. Manually corrupt the managed file (add `bogus;`), hit Save again → rollback restores prior content, red notification.
8. Confirm `storage/logs/laravel.log` contains no managed-file bytes or sudo password.

---

## Existing Code to Reuse

- `App\Services\Nginx\NginxManager::listFiles` — discovery.
- `App\Services\Nginx\NginxManager::saveFile` sudo swap flow — factor the shared parts into a private helper shared with `writeManagedFile`.
- `App\Services\Nginx\NginxManager::pruneBackups` — reused verbatim.
- `App\Services\Ssh\Testing\FakeSshClient`/`FakeSshSession` — their existing `cp/mv/rm` interpreter already supports the managed-file lifecycle.
- `App\Filament\Resources\Servers\Pages\ManageServerNginx` — two-pane layout, Filament `Section`/`afterHeader`/`CodeEditor` patterns, notification plumbing.
- `app/Providers/Filament/AppPanelProvider.php` — resources/pages auto-discover; no panel edits.
