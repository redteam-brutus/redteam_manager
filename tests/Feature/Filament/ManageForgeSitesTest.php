<?php

declare(strict_types=1);

use App\Filament\Resources\Servers\Pages\ManageForgeSites;
use App\Models\Server;
use App\Models\User;
use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;
use App\Services\Nginx\Forge\ForgeSiteSettingsRenderer;
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

function serverForForgePage(): Server
{
    return Server::factory()->create([
        'host_fingerprint' => 'fingerprint-known',
        'use_sudo' => true,
        'sudo_password' => 'hunter2',
    ]);
}

it('mounts and loads the forge site list', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n/etc/nginx/forge-conf/3075742/site.conf\n");

    $server = serverForForgePage();

    Livewire::test(ManageForgeSites::class, ['record' => $server->id])
        ->assertSet('sites.0.siteId', '3075741')
        ->assertSet('sites.1.siteId', '3075742')
        ->assertSet('sites.0.hasManaged', false);
});

it('selects a site and hydrates the form from an existing managed file', function () {
    $renderer = new ForgeSiteSettingsRenderer;
    $content = $renderer->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        trackingTag: '</head>',
        scriptBody: '<script>var a=1;</script>',
    ));

    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->withFile('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $content);

    $server = serverForForgePage();

    Livewire::test(ManageForgeSites::class, ['record' => $server->id])
        ->call('selectSite', '3075741')
        ->assertSet('selectedSiteId', '3075741')
        ->assertSet('hasManaged', true)
        ->assertSet('data.analyticsEnabled', true)
        ->assertSet('data.scriptBody', '<script>var a=1;</script>');
});

it('saves a site and notifies success', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForForgePage();

    Livewire::test(ManageForgeSites::class, ['record' => $server->id])
        ->call('selectSite', '3075741')
        ->set('data.analyticsEnabled', true)
        ->set('data.scriptBody', '<script>x</script>')
        ->call('saveSite')
        ->assertNotified('Saved');

    expect($this->fake->files['/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf'] ?? null)
        ->not->toBeNull();
});

it('disables a site when all toggles are off', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");
    $this->fake->withFile('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', "# managed\n");

    $server = serverForForgePage();

    Livewire::test(ManageForgeSites::class, ['record' => $server->id])
        ->call('selectSite', '3075741')
        ->set('data.analyticsEnabled', false)
        ->set('data.conditionalAccessLog', false)
        ->call('saveSite')
        ->assertNotified('Saved');

    expect(array_key_exists('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $this->fake->files))
        ->toBeFalse();
});

it('notifies danger when nginx -t rejects the new managed file', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] bogus\n");

    $server = serverForForgePage();

    Livewire::test(ManageForgeSites::class, ['record' => $server->id])
        ->call('selectSite', '3075741')
        ->set('data.analyticsEnabled', true)
        ->set('data.scriptBody', '<script>x</script>')
        ->call('saveSite')
        ->assertNotified('Save refused — config invalid');
});

it('hydrates restrictToTargetPages from a gated managed file', function () {
    $renderer = new ForgeSiteSettingsRenderer;
    $content = $renderer->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>gated</script>',
        restrictToTargetPages: true,
    ));

    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->withFile('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $content);

    $server = serverForForgePage();

    Livewire::test(ManageForgeSites::class, ['record' => $server->id])
        ->call('selectSite', '3075741')
        ->assertSet('data.restrictToTargetPages', true)
        ->assertSet('data.analyticsEnabled', true)
        ->assertSet('data.scriptBody', '<script>gated</script>');
});

it('disables a managed file via the disable action', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");
    $this->fake->withFile('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', "# managed\n");

    $server = serverForForgePage();

    Livewire::test(ManageForgeSites::class, ['record' => $server->id])
        ->call('selectSite', '3075741')
        ->call('disableSite')
        ->assertNotified('Saved')
        ->assertSet('hasManaged', false);

    expect(array_key_exists('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $this->fake->files))
        ->toBeFalse();
});
