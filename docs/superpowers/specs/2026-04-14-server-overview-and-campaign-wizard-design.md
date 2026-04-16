# Server Overview + Campaign Wizard

## Context

We've shipped four file-shaped Filament pages per server (Nginx, Forge sites, Anti-bot, Logs). Running a real campaign today means walking across three of them in the right order: add target pages/countries on the anti-bot page, open each Forge site separately to toggle analytics + gates, remember to reload nginx. The operator's mental model is "one coherent setup per server"; the UI is "one page per managed file." This spec adds a per-server Overview page that surfaces current state in that one-coherent-setup view, plus a campaign wizard that drives anti-bot + Forge injector writes in a single flow.

**Scope (confirmed with user):**
- Files remain source of truth. **No new DB tables.** Wizard orchestrates existing `AntibotRegistry` + `ForgeSiteRegistry` writes.
- One active rule per server — save overwrites the per-site Forge injection, appends cross-cutting anti-bot lists.
- Overview replaces Edit as the default landing when clicking a server.
- Wizard leaves the analytics script body empty — operator pastes their own payload.

---

## Architecture

```
ServersTable  ── recordUrl = Overview::getUrl
        ↓
ManageServerOverview (Filament custom page, /{record}/overview)
   ├── reads AntibotRegistry::load + hasManaged
   ├── reads ForgeSiteRegistry::list
   └── CampaignWizard action (multi-step Filament Schema)
                ↓
         on apply:
           1. AntibotRegistry::load → append countries/pages → save
           2. for each chosen Forge site: ForgeSiteRegistry::save
           3. notify per step
```

Two SSH round-trips per Overview mount (anti-bot `readFile` + forge `listFiles`+`listForgeDomains`). Wizard apply touches N+1 SSH operations: one anti-bot write, one per chosen Forge site.

---

## Overview Page

### Route
- `'overview' => ManageServerOverview::route('/{record}/overview')` in `ServerResource::getPages()`.
- `ServersTable::recordUrl(fn ($record) => ManageServerOverview::getUrl(['record' => $record]))` so row clicks land here.
- Existing `Edit` / `Nginx` / `Forge sites` / `Anti-bot` / `Logs` row actions keep working — nothing deleted.

### Livewire state

```php
public array $antibotSummary = [];    // ['botCount' => int, 'countries' => list<string>, 'pageCount' => int, 'hasManaged' => bool]
public array $forgeSites = [];        // list<['siteId','domains','hasManaged','analyticsEnabled','gates']>
public ?array $wizardData = null;     // form state while wizard is open
public bool $wizardOpen = false;      // controlled by the Campaign action modal
```

`mount` / `refresh` pulls from the two registries and builds the summaries.

### Blade layout

```
┌─ Anti-bot ──────────────────────────────────────────┐
│  42 bot patterns · 🇮🇱 🇪🇬 (2 countries) · 3 target pages │
│  [Edit anti-bot →]                                   │
└──────────────────────────────────────────────────────┘

┌─ Forge sites (2) ────────────────────────────────────┐
│  3075741  nabdaljabha.com                            │
│    Injection: ON · gates: not-bot, fbclid, target-page│
│    [Edit →]                                          │
│  3075742  —                                          │
│    Injection: OFF                                    │
│    [Edit →]                                          │
└──────────────────────────────────────────────────────┘

Header actions: [+ New campaign]  [Validate nginx]  [Reload nginx]
```

Filament `Section` around each panel. The Forge sites list uses the same domain-chip pattern already on the Nginx + Forge pages.

Empty states:
- No Forge sites discovered → "This server has no forge-conf/*/site.conf entries. The wizard's Forge step won't have options."
- No anti-bot conf written yet → summary row shows "Not configured — pre-filled bot list available" with an Edit shortcut.

---

## Campaign Wizard

### Trigger
Header `Action::make('campaign')->form([...])->steps([...])` on the Overview page. Filament 5's Action modal supports multi-step schemas via `Wizard::make(...)->steps([...])`.

### Steps

**Step 1 — Target scope**

| Component | Field | Source |
|---|---|---|
| `Select::make('forgeSiteIds')->multiple()` | list of Forge site IDs | `$this->forgeSites` with label `"{siteId} – {first domain or '—'}"` |
| `Select::make('targetCountries')->multiple()->searchable()` | list of ISO2 | `Countries::options()` |
| `Repeater::make('targetPages')->simple(TextInput::make('pattern'))` | list of URI regex bodies | user |

**Step 2 — Gating**

| Component | Field | Default |
|---|---|---|
| `Toggle::make('gateNotBot')` | bool | true |
| `Toggle::make('gateHasFbclid')` | bool | true |
| `Toggle::make('gateIsTargetCountry')` | bool | derived — on if Step 1 `targetCountries` non-empty |
| `Toggle::make('gateIsTargetPage')` | bool | derived — on if Step 1 `targetPages` non-empty |

"Derived" means we seed the default from Step 1 when first entering Step 2, but the user can still flip it. Implement via `mutateStateForValidationUsing` or a `->afterStateHydrated` callback on the wizard.

**Step 3 — Injection**

| Component | Field | Default |
|---|---|---|
| `Select::make('trackingTag')` | `</head>` / `</body>` / `<head>` / `<body>` | `</head>` |
| `Textarea::make('scriptBody')->rows(8)` | HTML/JS | **empty** |

Helper text on the textarea: "Paste your analytics/pixel snippet. Single quotes are escaped automatically for nginx."

**Step 4 — Review**

Read-only summary:
- Forge sites chosen (with domain)
- Target countries (with flags)
- Target pages (count + first three shown)
- Gates enabled (chips)
- Preview of the rendered anti-bot additions and the rendered Forge injector block

Built as a simple Blade view referenced by `->components([View::make(...)])`.

### Apply (on wizard submit)

`applyCampaign(array $data): void` on the Overview page:

```
$antibot = AntibotRegistry::load($server);

$antibot = new AntibotSettings(
    botPatterns: $antibot->botPatterns,        // untouched
    targetCountries: array_values(array_unique([...$antibot->targetCountries, ...$data['targetCountries']])),
    targetPages: array_values(array_unique([...$antibot->targetPages, ...$data['targetPages']])),
);

$result = AntibotRegistry::save($server, $antibot);
if (! $result->ok) {
    notify(danger); return;
}

foreach ($data['forgeSiteIds'] as $siteId) {
    $settings = new ForgeSiteSettings(
        analyticsEnabled: true,
        trackingTag: $data['trackingTag'],
        scriptBody: $data['scriptBody'],
        gateNotBot: (bool) $data['gateNotBot'],
        gateHasFbclid: (bool) $data['gateHasFbclid'],
        gateIsTargetCountry: (bool) $data['gateIsTargetCountry'],
        gateIsTargetPage: (bool) $data['gateIsTargetPage'],
        // conditionalAccessLog / accessLogPath left untouched — edit via Forge page
    );

    $perSite = ForgeSiteRegistry::save($server, $siteId, $settings);
    if (! $perSite->ok) {
        notify(danger, "Applied anti-bot but failed to update site {$siteId}: {$perSite->output}");
        return;
    }
}

refresh();    // reloads $antibotSummary + $forgeSites
notify(success, "Campaign applied to {$n} Forge sites.");
```

The existing per-registry rollback + validate-nginx flow handles each write's consistency. Cross-step partial state is documented in the failure notification ("Applied anti-bot but failed to update site X — fix and retry").

---

## Security

No new trust boundaries. Every write still routes through `NginxManager::writeManagedFile` / `deleteManagedFile` with `assertManagedPath` + sudo-aware swap. The wizard is purely a UX layer over existing services.

Input validation:
- `forgeSiteIds` entries must exist in the discovered site list (server-side check before applying).
- `targetCountries` validated by `AntibotSettingsRenderer` on save (already whitelist `/^[A-Z]{2}$/`).
- `targetPages` validated by `AntibotSettingsRenderer` on save (already rejects newlines / unescaped quotes).
- `scriptBody` passes through `ForgeSiteSettingsRenderer::escapeSingleQuoted` as today.

---

## Tests

### `tests/Feature/Filament/ManageServerOverviewTest.php`

- `mount` reads anti-bot + forge data, populates summaries
- `refresh` pulls fresh state
- smoke: route `/app/servers/{id}/overview` renders without error
- `applyCampaign` with countries+pages+forgeSites+gates+script calls AntibotRegistry::save once + ForgeSiteRegistry::save once per chosen site
- `applyCampaign` with anti-bot nginx -t failure → stops; no forge writes happen
- `applyCampaign` with per-site nginx -t failure → stops after that site; earlier successes stay applied; notification carries the failing site id
- target-country list is appended (not replaced) when the server already has some

### No unit test for the wizard form rendering itself — Filament's own tests cover Wizard mechanics.

---

## Verification (real server)

1. `php artisan test --compact` green.
2. `vendor/bin/pint --dirty --format agent` clean.
3. `npm run build`.
4. Click a server row → lands on Overview (not Edit). Summary panels render.
5. Click **New campaign**. Step through: pick two Forge sites, pick `IL`+`EG`, add `^/target-1/`, Step 2 shows gateIsTargetCountry + gateIsTargetPage on by default, leave them. Step 3: tag `</head>`, paste a `<script>…</script>`. Step 4: review, hit Apply.
6. On the server: `cat /etc/nginx/conf.d/redteam-antibot.conf` → countries + pages added; `cat /etc/nginx/forge-conf/<id>/server/redteam-analytics.conf` for each chosen site → analytics block with the composite scenario map.
7. `nginx -t` passes (both writes ran it).
8. Reload nginx; curl a target page with `?fbclid=x` from a target country → script injected.
9. Rerun the wizard with a new target page — existing entries survive, new one added.

---

## Files to Create / Modify

**New — Filament**
- `app/Filament/Resources/Servers/Pages/ManageServerOverview.php`
- `resources/views/filament/resources/servers/pages/manage-server-overview.blade.php`
- `resources/views/filament/resources/servers/partials/campaign-review.blade.php`  — Step 4 summary

**Modify — Filament**
- `app/Filament/Resources/Servers/ServerResource.php` — register `overview` page.
- `app/Filament/Resources/Servers/Tables/ServersTable.php` — set `recordUrl` to Overview; add **Overview** as a row action (primary, first in the row).

**New — tests**
- `tests/Feature/Filament/ManageServerOverviewTest.php`

No new services. No new migrations.

---

## Existing Code to Reuse

- `App\Services\Nginx\Antibot\AntibotRegistry` — load/save/hasManaged.
- `App\Services\Nginx\Forge\ForgeSiteRegistry` — list/find/save.
- `App\Support\Countries` — country options in the wizard.
- Filament `Wizard` component for the multi-step form (built-in).
- `ManageServerNginx` + `ManageForgeSites` — icon / blade / notification patterns.
