# Forge Injector — Target Page Gate

## Context

The Forge site injector currently injects analytics unconditionally on every response that contains `</head>`. The anti-bot conf generator now ships a global `$is_target_page` variable (set by `map $request_uri $is_target_page` in `/etc/nginx/conf.d/redteam-antibot.conf`). This spec wires the two together: one new per-site toggle gates the existing `sub_filter` on `$is_target_page=1` so analytics fires only for target pages.

Everything else about the Forge injector — file path, sudo plumbing, parser/renderer skeleton, UI page layout — stays as-is. This is a targeted feature add, not a rewrite.

---

## Design

### Data model

`ForgeSiteSettings` gains one boolean field:

```php
public bool $restrictToTargetPages = false,
```

No other signals (`$is_bot`, `$has_fbclid`, `$is_target_country`) are wired in v1. Follow-on spec can extend this.

### Renderer behaviour

| analyticsEnabled | restrictToTargetPages | output |
|---|---|---|
| false | any | no analytics block (same as today) |
| true | false | unconditional `sub_filter '</head>' '<script>…</script></head>';` (same as today) |
| true | true | scenario map + gated `sub_filter` (new) |

New output form for the gated case:

```
# --- analytics injection (gated on $is_target_page) ---
map $is_target_page $site_<siteId>_analytics_script {
    default '</head>';
    1       '<script>…</script></head>';
}
sub_filter_once on;
sub_filter '</head>' $site_<siteId>_analytics_script;
```

Variable name prefixed `site_` so nginx accepts it (names must start with a letter).

Script body escaping continues through the existing `escapeSingleQuoted` helper.

### Parser behaviour

Detect the per-site map block:
- regex: `/map\s+\$is_target_page\s+\$site_<siteId>_analytics_script\s*\{([^}]*)\}/`
- presence → `restrictToTargetPages = true`
- also re-extract `trackingTag` + `scriptBody` from the map's `1 '…'` row (matches existing extraction after the shape change)

Ungated analytics (existing shape) still parses the old way. Round-trip preserved for both shapes.

### UI

One new component in the existing "Analytics injection" section of `ManageForgeSites`:

```
Toggle::make('restrictToTargetPages')
    ->label('Only inject on target pages')
    ->helperText('Requires the anti-bot conf to be active with at least one target page configured.')
    ->live(debounce: 400),
```

Visible only when `analyticsEnabled=true` (existing Toggle).

`settingsFromData` hydrates the new field. `selectSite` + `buildSite` round-trip via the parser.

### Coupling note

When `restrictToTargetPages=true` and the anti-bot conf is missing or has no `$is_target_page` map, nginx `-t` will error out. The existing rollback flow handles this cleanly — user sees a red notification and the prior managed file is restored.

No new save-time cross-check in v1: the rollback + notification path is already the correct UX.

---

## Tests

### `ForgeSiteRegistryTest` (extend)

- renderer produces gated output when `restrictToTargetPages=true`
- renderer produces ungated output (existing shape) when `restrictToTargetPages=false`
- round-trip for a settings value with the gate on

### `ManageForgeSitesTest` (extend)

- `selectSite` hydrates `data.restrictToTargetPages` from an existing managed file using the gated shape

No new test files.

---

## Security

- No new file-system paths or sudo commands.
- Variable naming validated implicitly: `siteId` is already gated by `^[0-9]+$` via `ForgeSiteRegistry::assertSiteId`, so the generated nginx variable is always a valid identifier.
- Script body escaping unchanged.

---

## Files to modify

- `app/Services/Nginx/Forge/Dto/ForgeSiteSettings.php`
- `app/Services/Nginx/Forge/ForgeSiteSettingsRenderer.php`
- `app/Services/Nginx/Forge/ForgeSiteSettingsParser.php`
- `app/Filament/Resources/Servers/Pages/ManageForgeSites.php`
- `tests/Feature/Nginx/Forge/ForgeSiteRegistryTest.php` (extend)
- `tests/Feature/Filament/ManageForgeSitesTest.php` (extend)

No new files.

---

## Verification

1. `php artisan test --compact` green.
2. `vendor/bin/pint --dirty --format agent` clean.
3. `npm run build`.
4. Open `/app/servers/<id>/forge-sites`, pick a site, enable analytics + enable "Only inject on target pages", Save.
5. Verify `forge-conf/<id>/server/redteam-analytics.conf` contains the `$site_<id>_analytics_script` map. `nginx -t` passes.
6. With the anti-bot conf configured (target pages include the test URL): `curl https://<site>/target-page/` → script injected.
7. `curl https://<site>/other-page/` → script NOT injected.
8. Disable the anti-bot conf, hit Save again → rollback restores the prior file, red notification.
