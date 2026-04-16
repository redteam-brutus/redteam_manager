# Anti-Bot Conf Generator (Fully-Managed File)

## Context

Operators currently hand-edit three `map` directives at the http{} level of nginx.conf to classify incoming requests:

- `$is_bot` — regex against `$http_user_agent`
- `$is_target_country` — against `$http_cf_ipcountry`
- `$is_target_page` — against `$request_uri`

These feed downstream decisions: the per-site Forge injector already uses `$has_fbclid` / `$is_target_page` shaped booleans, and future specs will wire enforcement (block / honeypot / tag). This spec adds a single page that manages the three globals by rendering them into a fully-managed file at `/etc/nginx/conf.d/redteam-antibot.conf` — picked up automatically by nginx's existing `include /etc/nginx/conf.d/*.conf;` inside http{}.

The managed-file path is already whitelisted by the `assertManagedPath` regex shipped with the Forge injector (`conf.d/redteam-*.conf`). Reuse all existing sudo-swap plumbing.

**v1 scope (confirmed with user):**
- Three list-style inputs only: bot UA patterns, target country codes, target page URI regexes.
- `$has_fbclid` and `$is_fb_ref` stay out (they don't need a list).
- Enforcement (block / honeypot rewrite / tag-only) lands in a later spec.
- File-as-source-of-truth, consistent with the Forge injector. No DB tables.

---

## Architecture

```
ServerResource
  └── ManageServerAntibot (Filament custom page, /{record}/antibot)
         ↓
      AntibotRegistry
          ├── load(Server) : AntibotSettings
          ├── save(Server, AntibotSettings) : NginxSaveResult
          └── disable(Server) : NginxSaveResult
         ↓
      AntibotSettingsParser  ←── parses existing managed file
      AntibotSettingsRenderer ──→ produces canonical managed block
         ↓
      NginxManager::writeManagedFile / deleteManagedFile   (already shipped)
```

**Managed file:** `/etc/nginx/conf.d/redteam-antibot.conf`.

**Load flow:**
1. SSH readFile the managed path.
2. Missing → empty `AntibotSettings` (all three lists `[]`).
3. Present → `AntibotSettingsParser` extracts the three lists.

**Save flow:**
1. `AntibotSettingsRenderer::render(settings)` produces canonical content. Empty settings → empty string.
2. Empty → route to disable (delete managed file).
3. Otherwise → `NginxManager::writeManagedFile`: stage → backup if existing → install → `nginx -t` → rollback on fail.

**Disable flow:** `NginxManager::deleteManagedFile`.

**Scope interplay with Forge injector:** the maps define globally-scoped variables. Any server{} block — including the Forge-injected managed conf — can reference them. Not wired in v1; a later spec can reference `$is_target_page` from the Forge injector's sub_filter scenario map.

---

## Data Model

### DTO — `app/Services/Nginx/Antibot/Dto/AntibotSettings.php`

```php
final readonly class AntibotSettings
{
    /**
     * @param  list<string>  $botPatterns       regex alternatives, e.g. ['googlebot', 'bingbot', ...]
     * @param  list<string>  $targetCountries   2-letter uppercase codes, e.g. ['IL', 'EG']
     * @param  list<string>  $targetPages       URI regex bodies (no ~* prefix), e.g. ['^/page-1/', '^/page-2/']
     */
    public function __construct(
        public array $botPatterns = [],
        public array $targetCountries = [],
        public array $targetPages = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->botPatterns === []
            && $this->targetCountries === []
            && $this->targetPages === [];
    }
}
```

No `enabled` toggles. Empty list = map with only `default 0;` and no matches.

### Managed file format (canonical renderer output)

```
# Managed by redteam-manager. Do not edit by hand.

# --- Bot UA detection ($is_bot) ---
map $http_user_agent $is_bot {
    default 0;
    ~*(googlebot|bingbot|yandex|...) 1;
}

# --- Target country detection ($is_target_country) ---
map $http_cf_ipcountry $is_target_country {
    default 0;
    "IL" 1;
    "EG" 1;
}

# --- Target page detection ($is_target_page) ---
map $request_uri $is_target_page {
    default 0;
    "~*^/page-1/" 1;
    "~*^/page-2/" 1;
}
```

Empty lists render as `default 0;` only; the map still exists so any downstream reference to the variable keeps resolving.

### Renderer contract

`AntibotSettingsRenderer::render(AntibotSettings): string`

- Empty settings → `''` (registry routes to disable).
- Bot patterns → joined by `|` inside `~*(...)`. Each pattern pre-validated: letters/digits/`.-_+*?[]\/` and spaces only. Reject newlines, `|`, unescaped `)`/`(`.
- Country codes → validated `/^[A-Z]{2}$/`, emit as `"XX" 1;`.
- Target pages → stored as raw regex body, rendered as `"~*<body>" 1;`. Body must not contain newlines or unescaped double quotes.

Any validation failure → throw `InvalidArgumentException` (surfaced as red notification).

### Parser contract

`AntibotSettingsParser::parse(string): AntibotSettings`

- Finds each of the three map blocks by variable name (`$is_bot`, `$is_target_country`, `$is_target_page`).
- Bot block: captures the `~*(...)` group, splits on `|`, trims, drops empties.
- Country block: captures each `"XX" 1;` row.
- Page block: captures each `"~*<body>" 1;` row.
- Missing map block → that list = `[]`.

**Round-trip guarantee:** `parse(render($s)) === $s` for any valid settings.

---

## UI

### Route + discovery
- Custom page `ManageServerAntibot` at `/app/servers/{record}/antibot`.
- Register in `ServerResource::getPages()` as `'antibot' => ManageServerAntibot::route('/{record}/antibot')`.
- Row + header action in `ServersTable` alongside **Nginx** / **Forge sites**. Label **Anti-bot**, icon `Heroicon::OutlinedShieldCheck`.

### Page state
```php
public ?array $data = null;        // statePath for the form
public bool $hasManaged = false;
public ?string $renderedPreview = null;
```
`mount()` → calls `AntibotRegistry::load`, hydrates `$data` + `$hasManaged`.

### Form schema (Filament Schema, statePath `'data'`)

- `Section::make('Bot user agents')`
  - `TagsInput::make('botPatterns')` — helper text warns against `|` / parens / newlines; `live(debounce: 400)`.
- `Section::make('Target countries')`
  - `TagsInput::make('targetCountries')` — suggestions: `IL, EG, US, GB, DE, FR, AE, SA`; `live(debounce: 400)`.
- `Section::make('Target pages')`
  - `Repeater::make('targetPages')->simple(TextInput::make('pattern')->required())` — addActionLabel "Add a target page"; `live(debounce: 400)`.

`updatedData` recomputes `$renderedPreview`.

### Header actions
- **Save** — visible when `$data` is set. Confirmation modal with diff (current vs rendered). Calls `AntibotRegistry::save`. Notification by status.
- **Disable** — visible when `$hasManaged`. Confirms, calls `AntibotRegistry::disable`.
- **Reload nginx** — same pattern as other pages.

### Blade layout (`resources/views/filament/resources/servers/pages/manage-server-antibot.blade.php`)

Single-column page (one managed file per server):
- Top: `{{ $this->editor }}` — Filament-rendered form.
- Below: collapsible `x-filament::section` "Rendered preview" showing `$renderedPreview` in a `<pre>`. Empty state: "Nothing to write — Save will remove the managed file."
- Empty lists + no managed file → info: "Anti-bot maps not configured. Add bot patterns, countries, or target pages to enable."

### Going-forward rule compliance
Every interactive element uses Filament components (`Section`, `TagsInput`, `Repeater`, `TextInput`, `Action`, `Notification`). Custom Tailwind only on the preview `<pre>`, matching the Forge page.

---

## Security

- Path guard is the existing `NginxManager::assertManagedPath` regex (`conf.d/redteam-*.conf` or `forge-conf/<id>/server/redteam-*.conf`). No new FS trust boundary.
- **Bot pattern sanitization** — renderer whitelist: letters, digits, `.`, `-`, `_`, `+`, `*`, `?`, `[`, `]`, `\`, `/`, space. Reject newline, `|`, unescaped `(`/`)`. Reasoning: `|` and parens are alternation syntax; letting raw ones through lets a user break the group and run arbitrary map directives.
- **Country code whitelist** — `/^[A-Z]{2}$/`. Anything else rejected.
- **Target page sanitization** — reject body containing unescaped `"` or newline.
- Managed-file bytes produced entirely by the renderer; no shell interpolation of user input.
- Existing sudo-swap plumbing re-used; no new sudo commands.
- Sudo password never logged.
- Filament auth gate is the only authorization layer until the roles spec.

---

## Files to Create / Modify

**New — DTO**
- `app/Services/Nginx/Antibot/Dto/AntibotSettings.php`

**New — services**
- `app/Services/Nginx/Antibot/AntibotRegistry.php`
- `app/Services/Nginx/Antibot/AntibotSettingsParser.php`
- `app/Services/Nginx/Antibot/AntibotSettingsRenderer.php`

**New — Filament**
- `app/Filament/Resources/Servers/Pages/ManageServerAntibot.php`
- `resources/views/filament/resources/servers/pages/manage-server-antibot.blade.php`

**Modify — Filament**
- `app/Filament/Resources/Servers/ServerResource.php` — register `antibot` page.
- `app/Filament/Resources/Servers/Tables/ServersTable.php` — add **Anti-bot** row action.

**New — tests**
- `tests/Feature/Nginx/Antibot/AntibotRegistryTest.php`
- `tests/Feature/Filament/ManageServerAntibotTest.php`

---

## Tests

### `AntibotRegistryTest`

- `load` with no managed file → empty `AntibotSettings`.
- renderer round-trip: `parse(render($s)) === $s` for a non-trivial settings value.
- `save` with all three lists populated → sudo `mv` lands at `/etc/nginx/conf.d/redteam-antibot.conf`, `nginx -t` ran, returns `saved`.
- `save` when `nginx -t` fails and no prior file → file removed, status `invalid_config`.
- `save` when `nginx -t` fails over an existing managed file → backup restores, status `invalid_config`, file bytes unchanged.
- `save` with all lists empty → routes to disable; managed file removed, returns `saved`.
- `disable` with no managed file → returns `saved`, output `'Already disabled.'`.
- renderer rejects invalid country code (e.g. `"israel"`) with `InvalidArgumentException`.
- renderer rejects bot pattern containing a newline or pipe.
- parser tolerates missing map blocks (only bot map present → other lists empty).

### `ManageServerAntibotTest`

- `mount` loads settings (empty when no managed file).
- `saveSettings` with filled tags → success notification, `hasManaged` true.
- `saveSettings` with all empty → `hasManaged` false, managed file removed.
- invalid `nginx -t` → danger notification.
- `disable` action removes the managed file.

---

## Verification (real server)

1. `php artisan test --compact` green.
2. `vendor/bin/pint --dirty --format agent` clean.
3. `npm run build` so new Tailwind utilities compile.
4. Open `/app/servers/<id>/antibot`. Lists empty. Add `googlebot`, `bingbot` to bot patterns, `IL`, `EG` to countries, `^/page-1/` to pages. Save.
5. Verify `/etc/nginx/conf.d/redteam-antibot.conf` exists with three map blocks; `nginx -t` passes; reload.
6. `curl -A 'googlebot' https://<site>` → downstream site can consume `$is_bot`.
7. Add a pattern containing `|` → expect a red notification, file unchanged.
8. Corrupt the managed file by hand (`bogus;`), hit Save again — rollback restores, red notification.
9. Disable → file removed, `nginx -t` passes.

---

## Existing Code to Reuse

- `App\Services\Nginx\NginxManager::writeManagedFile` / `deleteManagedFile` / `assertManagedPath` — shipped with Forge injector.
- `App\Services\Ssh\Testing\FakeSshClient` / `FakeSshSession` — `cp`/`mv`/`rm` interpreter already handles the managed-file lifecycle.
- `App\Filament\Resources\Servers\Pages\ManageForgeSites` — patterns for Section/TagsInput/Repeater usage, notification plumbing, InteractsWithRecord mount flow.
- `app/Providers/Filament/AppPanelProvider.php` — resources/pages auto-discover; no panel edits.
