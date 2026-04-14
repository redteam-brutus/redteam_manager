<?php

declare(strict_types=1);

use App\Filament\Resources\Servers\Pages\ManageServerOverview;
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
    $this->fake->shouldReturnForCommand('-type d -not -name server', 0, '');
    $this->app->instance(SshClient::class, $this->fake);
});

function serverForOverviewPage(): Server
{
    return Server::factory()->create([
        'host_fingerprint' => 'fingerprint-known',
        'use_sudo' => true,
        'sudo_password' => 'hunter2',
    ]);
}

it('mounts and populates antibot + forge summaries', function () {
    $antibotContent = (new AntibotSettingsRenderer)->render(new AntibotSettings(
        botPatterns: ['googlebot', 'bingbot'],
    ));

    $this->fake->withFile('/etc/nginx/conf.d/redteam-antibot.conf', $antibotContent);
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");

    $server = serverForOverviewPage();

    $component = Livewire::test(ManageServerOverview::class, ['record' => $server->id])
        ->assertSet('antibotSummary.hasManaged', true)
        ->assertSet('antibotSummary.botCount', 2);

    expect($component->get('forgeSites'))->toHaveCount(1);
    expect($component->get('forgeSites.0.siteId'))->toBe('3075741');
});

it('surfaces every Forge domain in the overview state so the wizard can search by any of them', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3132788/site.conf\n");
    $this->fake->shouldReturnForCommand(
        '-type d -not -name server',
        0,
        "/etc/nginx/forge-conf/3132788/loveable-projects-zjpozggm.on-forge.com\n/etc/nginx/forge-conf/3132788/test.bestpropfirmsuk.com\n",
    );

    $server = serverForOverviewPage();

    $component = Livewire::test(ManageServerOverview::class, ['record' => $server->id]);

    expect($component->get('forgeSites.0.domains'))
        ->toBe(['loveable-projects-zjpozggm.on-forge.com', 'test.bestpropfirmsuk.com']);
});

it('renders the overview page without error when no state exists', function () {
    $this->fake->shouldReturn(0, '');

    $server = serverForOverviewPage();

    Livewire::test(ManageServerOverview::class, ['record' => $server->id])
        ->assertSet('antibotSummary.hasManaged', false)
        ->assertSet('forgeSites', [])
        ->assertSuccessful();
});

it('reuses a single SSH session for a multi-site campaign apply', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n/etc/nginx/forge-conf/3075742/site.conf\n/etc/nginx/forge-conf/3075743/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForOverviewPage();
    $startingConnects = $this->fake->connectCount;

    Livewire::test(ManageServerOverview::class, ['record' => $server->id])
        ->call('applyCampaign', [
            'forgeSiteIds' => ['3075741', '3075742', '3075743'],
            'targetCountries' => ['IL'],
            'targetPages' => [['pattern' => '^/offer/']],
            'gateNotBot' => false,
            'gateHasFbclid' => false,
            'gateIsTargetCountry' => true,
            'gateIsTargetPage' => true,
            'trackingTag' => '</head>',
            'scriptBody' => '<script>x</script>',
        ])
        ->assertNotified('Campaign applied');

    expect($this->fake->connectCount - $startingConnects)->toBeLessThanOrEqual(1);
});

it('auto-reloads nginx once at the end of a multi-site campaign', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n/etc/nginx/forge-conf/3075742/site.conf\n/etc/nginx/forge-conf/3075743/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForOverviewPage();

    Livewire::test(ManageServerOverview::class, ['record' => $server->id])
        ->call('applyCampaign', [
            'forgeSiteIds' => ['3075741', '3075742', '3075743'],
            'targetCountries' => ['IL'],
            'targetPages' => [['pattern' => '^/offer/']],
            'gateNotBot' => false,
            'gateHasFbclid' => false,
            'gateIsTargetCountry' => true,
            'gateIsTargetPage' => true,
            'trackingTag' => '</head>',
            'scriptBody' => '<script>x</script>',
        ]);

    $reloadCount = collect($this->fake->privilegedCommands)
        ->filter(fn (array $p): bool => str_contains($p['command'], 'systemctl reload nginx'))
        ->count();

    expect($reloadCount)->toBe(1);
});

it('applies a campaign: seeds antibot if empty + writes per-site gates per Forge site', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n/etc/nginx/forge-conf/3075742/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForOverviewPage();

    Livewire::test(ManageServerOverview::class, ['record' => $server->id])
        ->call('applyCampaign', [
            'forgeSiteIds' => ['3075741', '3075742'],
            'targetCountries' => ['IL'],
            'targetPages' => [['pattern' => '^/offer/']],
            'gateNotBot' => true,
            'gateHasFbclid' => true,
            'gateIsTargetCountry' => true,
            'gateIsTargetPage' => true,
            'trackingTag' => '</head>',
            'scriptBody' => '<script>track()</script>',
        ])
        ->assertNotified('Campaign applied');

    expect($this->fake->files['/etc/nginx/conf.d/redteam-antibot.conf'] ?? null)->not->toBeNull();
    expect($this->fake->files['/etc/nginx/conf.d/redteam-forge-3075741.conf'] ?? null)->not->toBeNull();
    expect($this->fake->files['/etc/nginx/conf.d/redteam-forge-3075742.conf'] ?? null)->not->toBeNull();
    expect($this->fake->files['/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf'] ?? null)->not->toBeNull();
    expect($this->fake->files['/etc/nginx/forge-conf/3075742/server/redteam-analytics.conf'] ?? null)->not->toBeNull();

    $http = $this->fake->files['/etc/nginx/conf.d/redteam-forge-3075741.conf'];
    $serverFile = $this->fake->files['/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf'];

    expect($http)->toContain('map ')
        ->and($http)->toContain('"IL" 1;')
        ->and($http)->toContain('"~*^/offer/" 1;')
        ->and($serverFile)->not->toContain('map ')
        ->and($serverFile)->toContain('sub_filter');
});

it('writes distinct country lists per site when applied in separate campaigns', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n/etc/nginx/forge-conf/3075742/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForOverviewPage();

    Livewire::test(ManageServerOverview::class, ['record' => $server->id])
        ->call('applyCampaign', [
            'forgeSiteIds' => ['3075741'],
            'targetCountries' => ['IL'],
            'targetPages' => [],
            'gateNotBot' => false,
            'gateHasFbclid' => false,
            'gateIsTargetCountry' => true,
            'gateIsTargetPage' => false,
            'trackingTag' => '</head>',
            'scriptBody' => '<script>il</script>',
        ])
        ->call('applyCampaign', [
            'forgeSiteIds' => ['3075742'],
            'targetCountries' => ['US'],
            'targetPages' => [],
            'gateNotBot' => false,
            'gateHasFbclid' => false,
            'gateIsTargetCountry' => true,
            'gateIsTargetPage' => false,
            'trackingTag' => '</head>',
            'scriptBody' => '<script>us</script>',
        ]);

    $siteAHttp = $this->fake->files['/etc/nginx/conf.d/redteam-forge-3075741.conf'] ?? '';
    $siteBHttp = $this->fake->files['/etc/nginx/conf.d/redteam-forge-3075742.conf'] ?? '';

    expect($siteAHttp)->toContain('"IL" 1;')
        ->and($siteAHttp)->not->toContain('"US" 1;')
        ->and($siteBHttp)->toContain('"US" 1;')
        ->and($siteBHttp)->not->toContain('"IL" 1;');
});

it('skips everything when no forge sites are selected or script body is empty', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForOverviewPage();

    Livewire::test(ManageServerOverview::class, ['record' => $server->id])
        ->call('applyCampaign', [
            'forgeSiteIds' => [],
            'targetCountries' => ['IL'],
            'targetPages' => [['pattern' => '^/offer/']],
            'gateNotBot' => false,
            'gateHasFbclid' => false,
            'gateIsTargetCountry' => false,
            'gateIsTargetPage' => false,
            'trackingTag' => '</head>',
            'scriptBody' => '',
        ])
        ->assertNotified('Campaign nothing to apply');

    expect(array_key_exists('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $this->fake->files))->toBeFalse();
});

it('aborts the campaign when the antibot seed fails nginx -t', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] bogus\n");

    $server = serverForOverviewPage();

    Livewire::test(ManageServerOverview::class, ['record' => $server->id])
        ->call('applyCampaign', [
            'forgeSiteIds' => ['3075741'],
            'targetCountries' => ['IL'],
            'targetPages' => [['pattern' => '^/offer/']],
            'gateNotBot' => true,
            'gateHasFbclid' => false,
            'gateIsTargetCountry' => false,
            'gateIsTargetPage' => false,
            'trackingTag' => '</head>',
            'scriptBody' => '<script>track()</script>',
        ])
        ->assertNotified('Campaign failed — anti-bot seed');

    expect(array_key_exists('/etc/nginx/conf.d/redteam-antibot.conf', $this->fake->files))->toBeFalse();
    expect(array_key_exists('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $this->fake->files))->toBeFalse();
});

it('does not touch antibot when the existing bot list is non-empty', function () {
    $existing = (new AntibotSettingsRenderer)->render(new AntibotSettings(
        botPatterns: ['googlebot'],
    ));

    $this->fake->withFile('/etc/nginx/conf.d/redteam-antibot.conf', $existing);
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $server = serverForOverviewPage();

    Livewire::test(ManageServerOverview::class, ['record' => $server->id])
        ->call('applyCampaign', [
            'forgeSiteIds' => ['3075741'],
            'targetCountries' => ['EG'],
            'targetPages' => [],
            'gateNotBot' => true,
            'gateHasFbclid' => false,
            'gateIsTargetCountry' => true,
            'gateIsTargetPage' => false,
            'trackingTag' => '</head>',
            'scriptBody' => '<script>x</script>',
        ])
        ->assertNotified('Campaign applied');

    expect($this->fake->files['/etc/nginx/conf.d/redteam-antibot.conf'])->toBe($existing);
});
