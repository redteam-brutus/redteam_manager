# Nginx Raw Editor — Spec

## Context

The browse/validate/reload spec ships a read-only Nginx page. Operators now need to edit files from the app: the anti-bot block in `nginx.conf`, the app-owned snippets in `conf.d`, and the Forge-managed `site.conf` inside `forge-conf/<id>/`. This spec adds a raw whole-file editor with strong safety rails. Later specs layer structured generators (anti-bot strategy, Forge injector) on top of this write path.

**Existing code this spec extends:**
- `app/Services/Nginx/NginxManager.php`, `app/Services/Ssh/Contracts/SshSession.php`, `app/Services/Ssh/PhpSecLibSshSession.php`, `app/Services/Ssh/Testing/FakeSshSession.php`, `app/Services/Ssh/Testing/FakeSshClient.php`, `app/Services/Ssh/SshConnectionManager.php`, `app/Filament/Resources/Servers/Pages/ManageServerNginx.php`, `resources/views/filament/resources/servers/pages/manage-server-nginx.blade.php`.

**Key decisions (confirmed with user):**
- Whole-file editing only; no structured field editor yet.
- Pre-save concurrency guard via SHA256 hash check; writes refused if the on-disk file changed since load.
- Atomic overwrite via SFTP: write `.redteam.tmp` → write `.bak.<ts>` → rename tmp into place.
- Post-write `nginx -t` validation; automatic rollback from the backup if validation fails.
- Reload is still a separate button; save never reloads implicitly.
- Retention: newest 10 `.bak.*` siblings per file kept.
- File prefix/sentinel conventions documented but not enforced in this spec.

**Out of scope:** anti-bot strategy generator, Forge site injection block editor, log tail, snippet library, structured block editing by sentinel markers, SSH key management beyond existing encrypted cast.

---

## Safety / Write Flow

Every save goes through this sequence. Each step may fail; failure modes map to discrete return statuses.

1. `NginxManager::assertWithinRoot($path)` — reuse existing guard.
2. `currentHash = sha256 of the file on disk` (via `fileHash()`).
3. If `currentHash !== expectedHash` → return `NginxSaveResult(ok: false, status: 'stale', output: ...)`. Nothing written.
4. `timestamp = now()->format('YmdHis')`. Derive `tmpPath = "$path.redteam.tmp"` and `backupPath = "$path.bak.$timestamp"`.
5. `ssh->writeFile(tmpPath, content)` (SFTP `put`).
6. `current = ssh->readFile(path)` (SFTP `get`).
7. `ssh->writeFile(backupPath, current)` (SFTP `put`).
8. `ssh->moveFile(tmpPath, path)` (SFTP `rename`) — atomic on the same filesystem.
9. `validate(server)` — runs `nginx -t 2>&1`.
10. If validation fails: `ssh->moveFile(backupPath, path)` to restore, and return `NginxSaveResult(ok: false, status: 'invalid_config', output: nginx_output)`.
11. On success: `pruneBackups(server, path)` to keep the newest 10. Return `NginxSaveResult(ok: true, status: 'saved', output: ..., backupPath: $backupPath)`.

Secondary safety:
- If tmp write fails early → return `io_error`, leave disk untouched.
- If the rename step fails → best-effort delete of the tmp and return `io_error`.
- If the rollback move in step 10 fails → return a critical `io_error` with the backup path so an operator can restore manually. This is the rare worst case.

---

## Data / DTOs

Create `App\Services\Nginx\Dto\NginxSaveResult`:

```
final readonly class NginxSaveResult
{
    public function __construct(
        public bool $ok,
        public string $status, // 'saved' | 'stale' | 'invalid_config' | 'io_error'
        public string $output,
        public ?string $backupPath = null,
    ) {}
}
```

No migrations, no schema changes. Purely code.

---

## SSH Layer Additions

### Contract — `app/Services/Ssh/Contracts/SshSession.php`

```
public function writeFile(string $path, string $content): void;

public function moveFile(string $from, string $to): void;

public function deleteFile(string $path): void;

public function fileExists(string $path): bool;
```

### `PhpSecLibSshSession`
- All four go through the already-lazy SFTP handle.
- `writeFile` → `$this->sftp()->put($path, $content)`. Failure → `SshCommandException`.
- `moveFile` → `$this->sftp()->rename($from, $to)`. Failure → `SshCommandException`.
- `deleteFile` → `$this->sftp()->delete($path)`. Failure → `SshCommandException`.
- `fileExists` → `$this->sftp()->file_exists($path)`. Returns bool.

### `FakeSshSession`
- Backed by `FakeSshClient::$files` array.
- `writeFile` → assigns to the map, appends entry to `$client->writes` log (`['path' => ..., 'content' => ...]`).
- `moveFile` → moves the key in `$client->files`; throws `SshCommandException` if `$from` missing.
- `deleteFile` → unsets key.
- `fileExists` → `array_key_exists`.

`FakeSshClient` adds:
- `public array $writes = [];`
- `public function withFile(string $path, string $content): self` (already present).
- `public function listWrites(): array { return $this->writes; }` (optional helper).

### `SshConnectionManager`

Add one-shot wrappers for each method. All open an authenticated session, perform the action, disconnect in `finally`. Mirrors the existing `run`, `readFile`, `listFiles` wrappers.

```
public function writeFile(Server $server, string $path, string $content): void;
public function moveFile(Server $server, string $from, string $to): void;
public function deleteFile(Server $server, string $path): void;
public function fileExists(Server $server, string $path): bool;
```

**Privilege note:** SFTP obeys the SSH user's filesystem permissions. If the SSH user isn't root, the nginx directory and its subtrees must be writable by that user (common on Forge boxes; otherwise the user adjusts group membership or ACLs once). This is documented in the spec and surfaced as a red notification if a write fails with a permissions error.

---

## NginxManager Additions

New methods on `App\Services\Nginx\NginxManager`:

```
public function fileHash(Server $server, string $path): string;

public function saveFile(Server $server, string $path, string $content, string $expectedHash): NginxSaveResult;

private function pruneBackups(Server $server, string $path): void;
```

Implementation notes:
- `fileHash($server, $path)` → `sha256sum <quoted-path> | awk '{print $1}'` via `$this->ssh->run($server, ...)`. Returns the hex digest or throws a `SshCommandException` if the shell call fails. Uses `escapeshellarg`.
- `saveFile` implements the flow in the Safety section. It never calls reload; it only ever validates.
- `pruneBackups` calls `$this->ssh->listFiles($server, "{$path}.bak.*")` (SFTP glob already in contract), sorts descending by the trailing timestamp, keeps the newest 10, deletes the rest via `$this->ssh->deleteFile`.

---

## Filament UI

### `ManageServerNginx` page

New Livewire state:
```
public ?string $draftContent = null;
public ?string $openedHash = null;
public bool $dirty = false;
public bool $editing = false;
```

Hook changes on `selectFile($path)`:
- After `$this->fileContent = ...`, also set:
  - `$this->draftContent = $this->fileContent;`
  - `$this->openedHash = hash('sha256', $this->fileContent);`
  - `$this->dirty = false;`
  - `$this->editing = false;`

Livewire hook `updatedDraftContent()`:
- Sets `$this->dirty = ($this->draftContent !== $this->fileContent);`.

New methods:
- `toggleEdit()` → flips `$editing`. When switching off without saving, also calls `discardDraft()` if `$dirty`.
- `discardDraft()` → resets `$draftContent` back to `$fileContent`, clears `$dirty`.
- `saveDraft()` → calls `NginxManager::saveFile($record, $selectedPath, $draftContent, $openedHash)`. Handles each status:
  - `saved` → green notification "Saved with backup.", body includes `backupPath`; update `$fileContent = $draftContent`; refresh `$openedHash`; `$dirty = false`; offer a `Reload nginx` button inline in the notification that fires the existing `runReload()`.
  - `stale` → red notification "File changed on disk. Reload the file to see the latest version."; offer a `Reload file` inline button that calls `selectFile($this->selectedPath)` again.
  - `invalid_config` → red notification "Save refused — config invalid"; body contains the `nginx -t` output.
  - `io_error` → red notification with the raw error string.

New header actions:
- `Save` action (primary, icon save) — visible only when `$editing && $selectedPath !== null && $dirty`. Opens a confirmation modal showing the unified diff (computed server-side via `sebastian/diff`) with `Save` and `Cancel`. Confirm → `saveDraft()`.
- Existing `Validate config` and `Reload nginx` actions remain.

### Blade view

Right pane gains a two-state mode driven by `$editing`:
- **Preview (default)** — existing `<pre>` readonly view. Header shows `[Edit]` button (calls `toggleEdit`).
- **Edit** — replaces the `<pre>` with the Filament form component `CodeEditor` bound to `wire:model.live.debounce.500ms="draftContent"`, using `->language('plaintext')` (there is no `nginx` language yet). Header shows `[Save]` (via the Filament Action), `[Discard]` (calls `discardDraft`), `[Cancel]` (calls `toggleEdit`).

The `CodeEditor` sits inside a `Filament\Schemas\Schema` the page exposes via a `form(Schema)` method, so the component hydration flows through Filament (same pattern as the custom pages in the Filament docs).

### Diff modal

The confirmation modal for `Save`:
- Title: `Save {{ $selectedPath }}`.
- Description: a summary line — `"Applying a {{ $addedLines }} line additions / {{ $removedLines }} line removals diff."`
- Modal content: a `<pre>` showing the unified diff produced by `new \SebastianBergmann\Diff\Differ(new \SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder)`, with `old = $fileContent`, `new = $draftContent`. `sebastian/diff` is already pulled in via PHPUnit, so no composer change is needed.

---

## Security

- Path-traversal guard reused; writes rejected for paths outside `/etc/nginx`.
- Concurrency guarded by SHA256 hash check before writing.
- Atomic rename prevents half-written configs (same filesystem).
- Backup produced before overwrite; automatic rollback on `nginx -t` failure.
- File content flows through SFTP bytes only — no shell interpolation.
- SFTP relies on filesystem permissions of the SSH user; no sudo required for writes.
- `$hidden` on `sudo_password` still applies (no change).
- Filament authenticated-user gate is sufficient; policies land with the roles spec.

---

## Files to Create / Modify

**Create**
- `app/Services/Nginx/Dto/NginxSaveResult.php`
- `tests/Feature/Nginx/NginxManagerWriteTest.php`
- `tests/Feature/Filament/ManageServerNginxEditorTest.php`

**Modify**
- `app/Services/Ssh/Contracts/SshSession.php`
- `app/Services/Ssh/PhpSecLibSshSession.php`
- `app/Services/Ssh/Testing/FakeSshSession.php`
- `app/Services/Ssh/Testing/FakeSshClient.php`
- `app/Services/Ssh/SshConnectionManager.php`
- `app/Services/Nginx/NginxManager.php`
- `app/Filament/Resources/Servers/Pages/ManageServerNginx.php`
- `resources/views/filament/resources/servers/pages/manage-server-nginx.blade.php`

---

## Tests

`tests/Feature/Nginx/NginxManagerWriteTest.php`:
- Saves a file → tmp written, backup written, rename recorded, `nginx -t` called; returns `status=saved` with `backupPath`.
- Stale hash → no writes; returns `status=stale`.
- `nginx -t` fails after write → restore move recorded; returns `status=invalid_config` with output.
- Tmp write fails → returns `status=io_error`; no rename attempted.
- `pruneBackups` deletes everything beyond the newest 10.
- `fileHash` uses `sha256sum` via run.

`tests/Feature/Filament/ManageServerNginxEditorTest.php`:
- Selecting a file populates `draftContent` and `openedHash`.
- Editing the draft sets `dirty` to true.
- `saveDraft` with a passing config notifies success and clears `dirty`.
- `saveDraft` with a stale hash notifies "File changed on disk".
- `saveDraft` with a failing `nginx -t` notifies "Save refused" and keeps `fileContent` unchanged.
- `discardDraft` resets the buffer.

All tests bind `FakeSshClient` in the container. No real network.

---

## Verification

1. `php artisan test --compact` — all green (existing + new).
2. `vendor/bin/pint --dirty --format agent` — no diffs.
3. On a real server:
   - Open Nginx page → select a file → click **Edit** → make a valid change → Save → green "Saved with backup" notification; check on the server via shell that `.bak.<ts>` exists alongside the file and the file has the new content.
   - Break a directive → Save → red "Save refused — config invalid" with the `nginx -t` output; confirm the file on disk is unchanged and no stray `.redteam.tmp` remains.
   - Two tabs: edit the same file in both, save in tab A, then switch to tab B and try to save → red "File changed on disk".
   - Save 11 times → only 10 newest `.bak.*` siblings remain (older one deleted).
4. `storage/logs/laravel.log` — no file contents or sudo passwords present.
