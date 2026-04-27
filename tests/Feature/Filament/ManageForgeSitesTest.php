<?php

declare(strict_types=1);

use App\Filament\Resources\Servers\Pages\ManageForgeSites;
use App\Models\Server;
use App\Models\User;
use App\Services\Nginx\Forge\Dto\ForgeSiteCustomLog;
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

it('auto-selects the site named in the ?site= query param on mount', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n/etc/nginx/forge-conf/3075742/site.conf\n");

    $server = serverForForgePage();

    Livewire::withQueryParams(['site' => '3075742'])
        ->test(ManageForgeSites::class, ['record' => $server->id])
        ->assertSet('selectedSiteId', '3075742');
});

it('ignores an unknown ?site= query param rather than erroring out', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");

    $server = serverForForgePage();

    Livewire::withQueryParams(['site' => '9999999'])
        ->test(ManageForgeSites::class, ['record' => $server->id])
        ->assertSet('selectedSiteId', null);
});

it('selects a site and hydrates the form from an existing managed file', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        trackingTag: '</head>',
        scriptBody: '<script>var a=1;</script>',
    ));

    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->withFile('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $rendered->serverContext);

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
        ->set('data.siteLoggingEnabled', false)
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

it('hydrates multi-signal gates from a composite managed file', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>gated</script>',
        gateNotBot: true,
        gateHasFbclid: true,
        gateIsTargetPage: true,
    ));

    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->withFile('/etc/nginx/conf.d/redteam-forge-3075741.conf', $rendered->httpContext);
    $this->fake->withFile('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $rendered->serverContext);

    $server = serverForForgePage();

    Livewire::test(ManageForgeSites::class, ['record' => $server->id])
        ->call('selectSite', '3075741')
        ->assertSet('data.gateNotBot', true)
        ->assertSet('data.gateHasFbclid', true)
        ->assertSet('data.gateIsTargetCountry', false)
        ->assertSet('data.gateIsTargetPage', true)
        ->assertSet('data.scriptBody', '<script>gated</script>');
});

it('auto-reloads nginx after a successful save', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForForgePage();

    Livewire::test(ManageForgeSites::class, ['record' => $server->id])
        ->call('selectSite', '3075741')
        ->set('data.analyticsEnabled', true)
        ->set('data.scriptBody', '<script>x</script>')
        ->call('saveSite')
        ->assertNotified('Nginx reloaded');

    $reloadRan = collect($this->fake->privilegedCommands)->contains(
        fn (array $p): bool => str_contains($p['command'], 'systemctl reload nginx'),
    );

    expect($reloadRan)->toBeTrue();
});

it('saves a site with a custom log entry and persists the rendered config', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForForgePage();

    Livewire::test(ManageForgeSites::class, ['record' => $server->id])
        ->call('selectSite', '3075741')
        ->set('data.siteLoggingEnabled', true)
        ->set('data.customLogs', [
            [
                'slug' => 'fb_us_offer',
                'label' => 'Facebook → US → /offer/',
                'requireNotBot' => false,
                'requireFbclid' => true,
                'requireSocialReferer' => false,
                'requireTargetCountry' => true,
                'requireTargetPage' => true,
                'overrideCountries' => ['US'],
                'overridePages' => [['pattern' => '^/offer/']],
                'overrideSocialRefererHosts' => [],
            ],
        ])
        ->call('saveSite')
        ->assertNotified('Saved');

    $managed = $this->fake->files['/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf'] ?? '';
    expect($managed)->toContain('site-3075741-fb_us_offer.log');
});

it('hydrates the custom log Repeater from a managed file with overrides', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('3075741', new ForgeSiteSettings(
        siteLoggingEnabled: true,
        customLogs: [
            new ForgeSiteCustomLog(
                slug: 'fb_us_offer',
                label: 'fb_us_offer',
                requireFbclid: true,
                requireTargetCountry: true,
                requireTargetPage: true,
                overrideCountries: ['US'],
                overridePages: ['^/offer/'],
            ),
        ],
    ));

    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->withFile('/etc/nginx/conf.d/redteam-forge-3075741.conf', $rendered->httpContext);
    $this->fake->withFile('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $rendered->serverContext);

    $server = serverForForgePage();

    Livewire::test(ManageForgeSites::class, ['record' => $server->id])
        ->call('selectSite', '3075741')
        ->assertSet('data.siteLoggingEnabled', true)
        ->assertSet('data.customLogs.0.slug', 'fb_us_offer')
        ->assertSet('data.customLogs.0.requireFbclid', true)
        ->assertSet('data.customLogs.0.requireTargetCountry', true)
        ->assertSet('data.customLogs.0.requireTargetPage', true)
        ->assertSet('data.customLogs.0.overrideCountries', ['US'])
        ->assertSet('data.customLogs.0.overridePages.0.pattern', '^/offer/');
});

it('skips reserved and invalid slugs when hydrating settings from form data', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForForgePage();

    $component = Livewire::test(ManageForgeSites::class, ['record' => $server->id])
        ->call('selectSite', '3075741')
        ->set('data.siteLoggingEnabled', true)
        ->set('data.customLogs', [
            ['slug' => 'gate', 'label' => 'reserved', 'requireFbclid' => true],
            ['slug' => 'BAD-SLUG', 'label' => 'invalid', 'requireFbclid' => true],
            ['slug' => 'ok_slug', 'label' => 'good', 'requireFbclid' => true],
        ])
        ->call('saveSite');

    $managed = $this->fake->files['/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf'] ?? '';
    expect($managed)
        ->toContain('site-3075741-ok_slug.log')
        ->not->toContain('site-3075741-gate.log if=$site_3075741_log_gate_hit')
        ->not->toContain('site-3075741-BAD-SLUG.log');
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
