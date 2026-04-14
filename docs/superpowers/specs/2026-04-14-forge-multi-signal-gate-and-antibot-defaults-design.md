# Forge Multi-Signal Gate + Anti-Bot Defaults

## Context

Two independent follow-ons wrapped in one spec:

1. **Multi-signal gate on the Forge injector.** The last spec added a single `restrictToTargetPages` boolean that conditioned analytics injection on `$is_target_page`. Extend that to four independent gate toggles covering the full scenario: `$is_bot = 0`, `$has_fbclid = 1`, `$is_target_country = 1`, `$is_target_page = 1`. Mirrors the user's existing production scenario map.
2. **Pre-filled default bot UA list on the anti-bot page.** The anti-bot generator ships with the bot list empty. Pre-seed it with the operator's 42-entry known-bot list so new servers start useful.

No new services, routes, or tables. All behind existing pages / services.

---

## Part A — Forge Injector Multi-Signal Gate

### Data model

Replace `restrictToTargetPages` on `ForgeSiteSettings` with four booleans:

```php
public bool $gateNotBot = false,
public bool $gateHasFbclid = false,
public bool $gateIsTargetCountry = false,
public bool $gateIsTargetPage = false,
```

No back-compat shim: the previous field was shipped only this morning and has no production footprint yet.

### Signal table (renderer uses this verbatim)

| Gate field | Signal variable | Required value | Helper map emitted by renderer? |
|---|---|---|---|
| `gateNotBot` | `$is_bot` | `0` | no (from anti-bot conf) |
| `gateHasFbclid` | `$has_fbclid` | `1` | **yes** — prepend `map $arg_fbclid $has_fbclid { default 1; "" 0; }` |
| `gateIsTargetCountry` | `$is_target_country` | `1` | no (from anti-bot conf) |
| `gateIsTargetPage` | `$is_target_page` | `1` | no (from anti-bot conf) |

### Renderer output

Let `enabled = [gate for each gate toggle that's on]`.

**`analyticsEnabled && scriptBody` and no gates enabled** → unchanged ungated form (existing behaviour).

**`analyticsEnabled && scriptBody` and one or more gates enabled** → emit the helper map (if `gateHasFbclid`) + a scenario map + a gated `sub_filter`:

```
# Helper (only when gateHasFbclid is on)
map $arg_fbclid $has_fbclid {
    default 1;
    ""      0;
}

# Scenario map — single-signal form when exactly one gate is on:
map $<signalVar> $site_<siteId>_analytics_script {
    default '</head>';
    <requiredValue>       '<script>…</script></head>';
}

# Scenario map — composite form when two or more gates are on:
map "$<sig1>:$<sig2>:…" $site_<siteId>_analytics_script {
    default '</head>';
    "<v1>:<v2>:…"      '<script>…</script></head>';
}

sub_filter_once on;
sub_filter '</head>' $site_<siteId>_analytics_script;
```

Signal ordering inside the composite key is fixed: `is_bot`, `has_fbclid`, `is_target_country`, `is_target_page` (only enabled ones appear). Fixed order matters for round-trip.

### Parser

Recognise both shapes:

- **Single-signal:** `map $<signalVar> $site_<id>_analytics_script { default '<tag>'; <value> '<body+tag>'; }` → the one gate whose signal matches is set true.
- **Composite:** `map "$<sig1>:$<sig2>:…" $site_<id>_analytics_script { default '<tag>'; "<v1>:<v2>:…" '<body+tag>'; }` → for each colon-separated signal token, set the matching gate true (value must match the required value from the signal table; mismatches are ignored).

Helper map presence is a by-product of `gateHasFbclid`; parser doesn't inspect it independently.

Round-trip: `parse(render($settings))` must equal `$settings` for any combination of gate booleans.

### UI

In `ManageForgeSites`, add a new `Section::make('Injection gates')` *below* the existing "Analytics injection" section (so toggles that say "enable Y when X is true" read naturally):

```
Section::make('Injection gates')
    ->description('Gate sub_filter injection on global signals. Requires the anti-bot conf for $is_bot / $is_target_country / $is_target_page.')
    ->schema([
        Toggle::make('gateNotBot')
            ->label('Only real users (not bots)')
            ->live(debounce: 400),
        Toggle::make('gateHasFbclid')
            ->label('Only when ?fbclid is present')
            ->live(debounce: 400),
        Toggle::make('gateIsTargetCountry')
            ->label('Only target countries')
            ->live(debounce: 400),
        Toggle::make('gateIsTargetPage')
            ->label('Only target pages')
            ->live(debounce: 400),
    ])
```

Remove the previous `restrictToTargetPages` Toggle from the "Analytics injection" section.

Update `selectSite`, `disableSite`, and `settingsFromData` to carry the four new fields.

### Tests (extend existing files)

In `ForgeSiteRegistryTest`:
- renderer emits single-signal map when exactly one gate is on (check shape for `gateNotBot`, `gateHasFbclid`, `gateIsTargetCountry`, `gateIsTargetPage`)
- renderer emits composite map when two or more gates are on; helper `$has_fbclid` map emitted iff `gateHasFbclid` is on
- parser round-trips single-signal for each gate
- parser round-trips composite for a 3-signal combination (e.g. `gateNotBot + gateHasFbclid + gateIsTargetPage`)

In `ManageForgeSitesTest`:
- replace the existing `restrictToTargetPages` hydration test with a multi-gate hydration assertion

Drop the now-obsolete renderer tests that asserted on `restrictToTargetPages`.

---

## Part B — Anti-Bot Page: Default Bot List

### Data

Add a public constant on `AntibotSettings` with the 42-entry list:

```php
public const DEFAULT_BOT_PATTERNS = [
    'googlebot','bingbot','yandex','baiduspider','slurp','duckduckbot',
    'sogou','exabot','facebot','facebookexternalhit','twitterbot','rogerbot',
    'linkedinbot','embedly','slackbot','discordbot','whatsapp','skypeuripreview',
    'telegrambot','quora','pinterest','vkShare','applebot','ahrefsbot',
    'semrushbot','mj12bot','dotbot','petalsbot','grapeshot','megaindex',
    'magpie-crawler','scrapy','curl','wget','python-requests','python-urllib',
    'libwww-perl','httpclient','java','ruby','postmanruntime','bot','spider',
    'crawler','scraper','crawling','archiver','archive.org_bot',
];
```

(Order preserved from user-provided list — renderer sanitises each entry anyway.)

### Page behaviour

In `ManageServerAntibot::loadSettings`, when there's no managed file on the server AND the parsed settings are empty, pre-fill `data.botPatterns` with `AntibotSettings::DEFAULT_BOT_PATTERNS`. Other two lists stay empty.

```php
$settings = $registry->load(...);
$this->hasManaged = $registry->hasManaged(...);

$bots = $settings->botPatterns;

if (! $this->hasManaged && $bots === []) {
    $bots = AntibotSettings::DEFAULT_BOT_PATTERNS;
}

$this->data = [
    'botPatterns' => $bots,
    ...
];
```

If the user clears all three lists and hits Save, the disable path fires and the managed file is removed — empty state is respected.

### Tests (extend `ManageServerAntibotTest`)

- mounting against a server with no managed file pre-fills `data.botPatterns` with the default list (spot-check first + last entry, assert length > 40)
- mounting against a server with an existing managed file keeps the parsed list (pre-fill does NOT overwrite)

---

## Security

- Signal table is fixed in code; user can't inject arbitrary nginx variables via the UI. Each gate toggle maps to a known signal.
- Composite key uses colon-joined already-validated variable names; no user-string interpolation.
- Default bot list entries go through the existing renderer sanitiser (letters/digits/`.-_+*?[]\/` and space only, no pipes or parens) — verified by renderer validation on save. Each entry in the provided list passes the whitelist.

---

## Verification (real server)

1. `php artisan test --compact` green.
2. `vendor/bin/pint --dirty --format agent` clean.
3. `npm run build`.
4. Open the anti-bot page on a fresh server — bot list pre-populated with 42 entries; save; verify managed file written.
5. Open a Forge site; enable analytics; enable all four gates; save; verify the managed file emits both the `$has_fbclid` helper map and a 4-signal composite scenario map keyed `"$is_bot:$has_fbclid:$is_target_country:$is_target_page"` with `"0:1:1:1"` as the match row.
6. `curl -A googlebot 'https://<site>/target-page/?fbclid=x'` → script NOT injected (bot gate trips).
7. `curl 'https://<site>/target-page/?fbclid=x'` with a target-country IP → script injected.
8. Disable one gate, save — scenario map regenerates with three signals; downstream tests adjust accordingly.

---

## Files to Modify

- `app/Services/Nginx/Forge/Dto/ForgeSiteSettings.php`
- `app/Services/Nginx/Forge/ForgeSiteSettingsRenderer.php`
- `app/Services/Nginx/Forge/ForgeSiteSettingsParser.php`
- `app/Filament/Resources/Servers/Pages/ManageForgeSites.php`
- `app/Services/Nginx/Antibot/Dto/AntibotSettings.php`
- `app/Filament/Resources/Servers/Pages/ManageServerAntibot.php`
- `tests/Feature/Nginx/Forge/ForgeSiteRegistryTest.php` (extend + retire obsolete assertions)
- `tests/Feature/Filament/ManageForgeSitesTest.php` (extend + retire obsolete assertion)
- `tests/Feature/Filament/ManageServerAntibotTest.php` (extend)

No new files.
