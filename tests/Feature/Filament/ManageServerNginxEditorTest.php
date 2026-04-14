<?php

declare(strict_types=1);

use App\Filament\Resources\Servers\Pages\ManageServerNginx;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->fake->shouldReturnForCommand('-type d -not -name server', 0, '');
    $this->app->instance(SshClient::class, $this->fake);
});

function editorServer(): Server
{
    return Server::factory()->create([
        'host_fingerprint' => 'fingerprint-known',
    ]);
}

function seedEditorFile(FakeSshClient $fake, string $path, string $content): string
{
    $fake->withFile($path, $content);
    $hash = hash('sha256', $content);
    $fake->shouldReturnForCommand('sha256sum '.escapeshellarg($path), 0, $hash."\n");

    return $hash;
}

it('populates draft and hash when a file is selected', function () {
    $path = '/etc/nginx/conf.d/site.conf';
    $this->fake->shouldReturn(0, $path."\n");
    $this->fake->withFile($path, "server {}\n");

    $server = editorServer();

    Livewire::test(ManageServerNginx::class, ['record' => $server->id])
        ->call('selectFile', $path)
        ->assertSet('fileContent', "server {}\n")
        ->assertSet('draftContent', "server {}\n")
        ->assertSet('openedHash', hash('sha256', "server {}\n"))
        ->assertSet('dirty', false);
});

it('marks the draft as dirty when the content changes', function () {
    $path = '/etc/nginx/conf.d/site.conf';
    $this->fake->shouldReturn(0, $path."\n");
    $this->fake->withFile($path, "server {}\n");

    $server = editorServer();

    Livewire::test(ManageServerNginx::class, ['record' => $server->id])
        ->call('selectFile', $path)
        ->set('draftContent', "server { listen 443; }\n")
        ->assertSet('dirty', true);
});

it('notifies success after a valid save and clears dirty', function () {
    $path = '/etc/nginx/conf.d/site.conf';
    $this->fake->shouldReturn(0, $path."\n");
    seedEditorFile($this->fake, $path, "server {}\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = editorServer();

    Livewire::test(ManageServerNginx::class, ['record' => $server->id])
        ->call('selectFile', $path)
        ->set('draftContent', "server { listen 443; }\n")
        ->call('saveDraft')
        ->assertNotified('Saved')
        ->assertSet('dirty', false)
        ->assertSet('fileContent', "server { listen 443; }\n");
});

it('notifies stale when the on-disk hash drifted', function () {
    $path = '/etc/nginx/conf.d/site.conf';
    $this->fake->shouldReturn(0, $path."\n");
    $this->fake->withFile($path, "server {}\n");
    // initial hash matches; then we simulate drift by changing the canned hash
    $this->fake->shouldReturnForCommand('sha256sum '.escapeshellarg($path), 0, hash('sha256', "server {}\n")."\n");

    $server = editorServer();

    $test = Livewire::test(ManageServerNginx::class, ['record' => $server->id])
        ->call('selectFile', $path);

    // drift: rewrite the canned hash to something else (simulating another process edited)
    $this->fake->shouldReturnForCommand('sha256sum '.escapeshellarg($path), 0, str_repeat('b', 64)."\n");

    $test
        ->set('draftContent', "server { listen 443; }\n")
        ->call('saveDraft')
        ->assertNotified('File changed on disk');
});

it('notifies invalid config and keeps fileContent when nginx -t fails', function () {
    $path = '/etc/nginx/conf.d/site.conf';
    $this->fake->shouldReturn(0, $path."\n");
    seedEditorFile($this->fake, $path, "server {}\n");
    $this->fake->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] boom\n");

    $server = editorServer();

    Livewire::test(ManageServerNginx::class, ['record' => $server->id])
        ->call('selectFile', $path)
        ->set('draftContent', "server { bogus;\n")
        ->call('saveDraft')
        ->assertNotified('Save refused — config invalid')
        ->assertSet('fileContent', "server {}\n")
        ->assertSet('dirty', true);
});

it('discardDraft resets the buffer', function () {
    $path = '/etc/nginx/conf.d/site.conf';
    $this->fake->shouldReturn(0, $path."\n");
    $this->fake->withFile($path, "server {}\n");

    $server = editorServer();

    Livewire::test(ManageServerNginx::class, ['record' => $server->id])
        ->call('selectFile', $path)
        ->set('draftContent', "changed\n")
        ->assertSet('dirty', true)
        ->call('discardDraft')
        ->assertSet('draftContent', "server {}\n")
        ->assertSet('dirty', false);
});
