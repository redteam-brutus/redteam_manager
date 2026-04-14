<?php

declare(strict_types=1);

use App\Filament\Resources\Servers\Pages\ManageServerAntibot;
use App\Models\Server;
use App\Models\User;
use App\Services\Nginx\Antibot\AntibotSettingsRenderer;
use App\Services\Nginx\Antibot\Dto\AntibotSettings;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->app->instance(SshClient::class, $this->fake);
});

function serverForAntibotPage(): Server
{
    return Server::factory()->create([
        'host_fingerprint' => 'fingerprint-known',
        'use_sudo' => true,
        'sudo_password' => 'hunter2',
    ]);
}

it('pre-fills the default bot list when no managed file exists', function () {
    $server = serverForAntibotPage();

    $component = Livewire::test(ManageServerAntibot::class, ['record' => $server->id])
        ->assertSet('hasManaged', false);

    $botPatterns = $component->get('data.botPatterns');
    expect($botPatterns)
        ->toBeArray()
        ->and(count($botPatterns))->toBeGreaterThan(40)
        ->and($botPatterns[0])->toBe('googlebot')
        ->and(end($botPatterns))->toBe('archive.org_bot');
});

it('keeps the parsed bot list intact when a managed file already exists', function () {
    $content = (new AntibotSettingsRenderer)->render(new AntibotSettings(
        botPatterns: ['googlebot'],
    ));
    $this->fake->withFile('/etc/nginx/conf.d/redteam-antibot.conf', $content);

    $server = serverForAntibotPage();

    Livewire::test(ManageServerAntibot::class, ['record' => $server->id])
        ->assertSet('hasManaged', true)
        ->assertSet('data.botPatterns', ['googlebot']);
});

it('saves settings and notifies success', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForAntibotPage();

    Livewire::test(ManageServerAntibot::class, ['record' => $server->id])
        ->set('data.botPatterns', ['googlebot', 'bingbot'])
        ->call('saveSettings')
        ->assertNotified('Saved')
        ->assertSet('hasManaged', true);

    expect($this->fake->files['/etc/nginx/conf.d/redteam-antibot.conf'] ?? null)
        ->not->toBeNull();
});

it('disables the managed file when the bot list is cleared', function () {
    $this->fake->withFile('/etc/nginx/conf.d/redteam-antibot.conf', "# managed\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForAntibotPage();

    Livewire::test(ManageServerAntibot::class, ['record' => $server->id])
        ->set('data.botPatterns', [])
        ->call('saveSettings')
        ->assertNotified('Saved')
        ->assertSet('hasManaged', false);

    expect(array_key_exists('/etc/nginx/conf.d/redteam-antibot.conf', $this->fake->files))
        ->toBeFalse();
});

it('notifies danger when nginx -t rejects the new anti-bot file', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] bogus\n");

    $server = serverForAntibotPage();

    Livewire::test(ManageServerAntibot::class, ['record' => $server->id])
        ->set('data.botPatterns', ['googlebot'])
        ->call('saveSettings')
        ->assertNotified('Save refused — config invalid');
});

it('disables via the disable action', function () {
    $this->fake->withFile('/etc/nginx/conf.d/redteam-antibot.conf', "# managed\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForAntibotPage();

    Livewire::test(ManageServerAntibot::class, ['record' => $server->id])
        ->call('disableAntibot')
        ->assertNotified('Saved')
        ->assertSet('hasManaged', false);

    expect(array_key_exists('/etc/nginx/conf.d/redteam-antibot.conf', $this->fake->files))
        ->toBeFalse();
});
