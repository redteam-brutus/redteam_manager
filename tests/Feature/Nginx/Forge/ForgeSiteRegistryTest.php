<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;
use App\Services\Nginx\Forge\ForgeSiteRegistry;
use App\Services\Nginx\Forge\ForgeSiteSettingsParser;
use App\Services\Nginx\Forge\ForgeSiteSettingsRenderer;
use App\Services\Nginx\NginxManager;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;

beforeEach(function () {
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->fake->shouldReturnForCommand('-type d -not -name server', 0, '');
    $this->app->instance(SshClient::class, $this->fake);
    $this->registry = app(ForgeSiteRegistry::class);
});

function aForgeServer(array $overrides = []): Server
{
    return Server::factory()->create(array_merge([
        'host_fingerprint' => 'fingerprint-known',
        'use_sudo' => true,
        'sudo_password' => 'hunter2',
    ], $overrides));
}

function seedListing(FakeSshClient $fake, array $paths): void
{
    $fake->shouldReturn(0, implode("\n", $paths)."\n");
}

it('lists forge sites discovered via nginx listing', function () {
    seedListing($this->fake, [
        '/etc/nginx/nginx.conf',
        '/etc/nginx/conf.d/ssl.conf',
        '/etc/nginx/forge-conf/3075741/site.conf',
        '/etc/nginx/forge-conf/3075741/server/access.conf',
        '/etc/nginx/forge-conf/3075742/site.conf',
    ]);

    $sites = $this->registry->list(aForgeServer());

    expect($sites)->toHaveCount(2)
        ->and($sites[0]->siteId)->toBe('3075741')
        ->and($sites[1]->siteId)->toBe('3075742')
        ->and($sites[0]->managedPath)->toBe('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf')
        ->and($sites[0]->hasManaged)->toBeFalse();
});

it('marks hasManaged=true when a managed file exists', function () {
    seedListing($this->fake, [
        '/etc/nginx/forge-conf/3075741/site.conf',
        '/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf',
    ]);
    $this->fake->withFile('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', "# managed\n");

    $sites = $this->registry->list(aForgeServer());

    expect($sites[0]->hasManaged)->toBeTrue();
});

it('includes domain subfolders as tags from listForgeDomains', function () {
    seedListing($this->fake, [
        '/etc/nginx/forge-conf/3075741/site.conf',
    ]);
    $this->fake->shouldReturnForCommand(
        '-type d -not -name server',
        0,
        "/etc/nginx/forge-conf/3075741/blessed-moss.on-forge.com\n/etc/nginx/forge-conf/3075741/example.com\n",
    );

    $sites = $this->registry->list(aForgeServer());

    expect($sites[0]->domains)->toBe(['blessed-moss.on-forge.com', 'example.com']);
});

it('find returns defaults when no managed file exists', function () {
    seedListing($this->fake, []);

    $site = $this->registry->find(aForgeServer(), '3075741');

    expect($site->hasManaged)->toBeFalse()
        ->and($site->settings->analyticsEnabled)->toBeFalse()
        ->and($site->settings->conditionalAccessLog)->toBeFalse();
});

it('find parses an existing managed file', function () {
    $renderer = new ForgeSiteSettingsRenderer;
    $content = $renderer->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        trackingTag: '</head>',
        scriptBody: '<script>console.log("hi")</script>',
        conditionalAccessLog: true,
        accessLogPath: '/var/log/nginx/site-fbclid.log',
    ));

    $this->fake->withFile('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $content);

    $site = $this->registry->find(aForgeServer(), '3075741');

    expect($site->hasManaged)->toBeTrue()
        ->and($site->settings->analyticsEnabled)->toBeTrue()
        ->and($site->settings->trackingTag)->toBe('</head>')
        ->and($site->settings->scriptBody)->toBe('<script>console.log("hi")</script>')
        ->and($site->settings->conditionalAccessLog)->toBeTrue()
        ->and($site->settings->accessLogPath)->toBe('/var/log/nginx/site-fbclid.log');
});

it('save writes the managed file via sudo mv', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $settings = new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>var x=1;</script>',
    );

    $result = $this->registry->save(aForgeServer(), '3075741', $settings);

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe('saved');

    $privileged = collect($this->fake->privilegedCommands);
    expect($privileged->contains(
        fn (array $p): bool => str_starts_with($p['command'], 'mv ')
            && str_contains($p['command'], '/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf')
    ))->toBeTrue();

    $managed = $this->fake->files['/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf'] ?? null;
    expect($managed)->not->toBeNull()
        ->and($managed)->toContain('sub_filter_once on;')
        ->and($managed)->toContain('<script>var x=1;</script>');
});

it('save removes the brand-new file on nginx -t failure', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] bogus\n");

    $settings = new ForgeSiteSettings(analyticsEnabled: true, scriptBody: '<script>x</script>');

    $result = $this->registry->save(aForgeServer(), '3075741', $settings);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe('invalid_config');

    expect(array_key_exists('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $this->fake->files))
        ->toBeFalse();
});

it('save restores the previous managed file on nginx -t failure', function () {
    $renderer = new ForgeSiteSettingsRenderer;
    $originalContent = $renderer->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>old</script>',
    ));

    $this->fake->withFile(
        '/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf',
        $originalContent,
    );

    $this->fake->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] bogus\n");

    $this->registry->save(aForgeServer(), '3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>new-and-broken</script>',
    ));

    expect($this->fake->files['/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf'])
        ->toBe($originalContent);
});

it('save with all toggles off routes to disable', function () {
    $this->fake->withFile('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', "# managed\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $result = $this->registry->save(aForgeServer(), '3075741', new ForgeSiteSettings);

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe('saved');

    expect(array_key_exists('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $this->fake->files))
        ->toBeFalse();
});

it('disable is a no-op when no managed file exists', function () {
    $result = $this->registry->disable(aForgeServer(), '3075741');

    expect($result->ok)->toBeTrue()
        ->and($result->output)->toBe('Already disabled.');
});

it('renderer emits a gated scenario map when restrictToTargetPages is on', function () {
    $renderer = new ForgeSiteSettingsRenderer;

    $content = $renderer->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
        restrictToTargetPages: true,
    ));

    expect($content)->toContain('map $is_target_page $site_3075741_analytics_script')
        ->and($content)->toContain('sub_filter \'</head>\' $site_3075741_analytics_script;')
        ->and($content)->not->toContain("sub_filter '</head>' '<script>");
});

it('renderer emits ungated analytics when restrictToTargetPages is off', function () {
    $renderer = new ForgeSiteSettingsRenderer;

    $content = $renderer->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
        restrictToTargetPages: false,
    ));

    expect($content)->not->toContain('$site_3075741_analytics_script')
        ->and($content)->toContain("sub_filter '</head>' '<script>x</script></head>';");
});

it('parser round-trips a gated analytics managed file', function () {
    $renderer = new ForgeSiteSettingsRenderer;
    $parser = new ForgeSiteSettingsParser;

    $original = new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>console.log("hi")</script>',
        conditionalAccessLog: true,
        accessLogPath: '/var/log/nginx/site-fbclid.log',
        restrictToTargetPages: true,
    );

    $parsed = $parser->parse($renderer->render('3075741', $original));

    expect($parsed->analyticsEnabled)->toBeTrue()
        ->and($parsed->restrictToTargetPages)->toBeTrue()
        ->and($parsed->trackingTag)->toBe('</head>')
        ->and($parsed->scriptBody)->toBe('<script>console.log("hi")</script>')
        ->and($parsed->conditionalAccessLog)->toBeTrue()
        ->and($parsed->accessLogPath)->toBe('/var/log/nginx/site-fbclid.log');
});

it('rejects non-numeric site ids', function () {
    $this->registry->find(aForgeServer(), '../etc/passwd');
})->throws(InvalidArgumentException::class, 'Invalid Forge site id');

it('rejects managed writes outside the forge-conf/server path', function () {
    app(NginxManager::class)->writeManagedFile(
        aForgeServer(),
        '/etc/nginx/nginx.conf',
        '# evil',
    );
})->throws(InvalidArgumentException::class, 'Managed path must match');
