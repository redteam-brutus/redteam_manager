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

function seedRenderedSite(FakeSshClient $fake, string $siteId, ForgeSiteSettings $settings): string
{
    $rendered = (new ForgeSiteSettingsRenderer)->render($siteId, $settings);

    if ($rendered->httpContext !== '') {
        $fake->withFile("/etc/nginx/conf.d/redteam-forge-{$siteId}.conf", $rendered->httpContext);
    }

    if ($rendered->serverContext !== '') {
        $fake->withFile("/etc/nginx/forge-conf/{$siteId}/server/redteam-analytics.conf", $rendered->serverContext);
    }

    return $rendered->serverContext;
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
    seedRenderedSite($this->fake, '3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        trackingTag: '</head>',
        scriptBody: '<script>console.log("hi")</script>',
        conditionalAccessLog: true,
        accessLogPath: '/var/log/nginx/site-fbclid.log',
    ));

    $site = $this->registry->find(aForgeServer(), '3075741');

    expect($site->hasManaged)->toBeTrue()
        ->and($site->settings->analyticsEnabled)->toBeTrue()
        ->and($site->settings->trackingTag)->toBe('</head>')
        ->and($site->settings->scriptBody)->toBe('<script>console.log("hi")</script>')
        ->and($site->settings->conditionalAccessLog)->toBeTrue()
        ->and($site->settings->accessLogPath)->toBe('/var/log/nginx/site-fbclid.log');
});

it('creates the forge-conf/<id>/server directory when it does not yet exist', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $this->registry->save(aForgeServer(), '3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
    ));

    $privileged = collect($this->fake->privilegedCommands);
    $hasMkdir = $privileged->contains(
        fn (array $p): bool => str_starts_with($p['command'], 'mkdir -p ')
            && str_contains($p['command'], '/etc/nginx/forge-conf/3075741/server')
    );

    expect($hasMkdir)->toBeTrue();
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
    $originalServer = seedRenderedSite($this->fake, '3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>old</script>',
    ));

    $this->fake->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] bogus\n");

    $this->registry->save(aForgeServer(), '3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>new-and-broken</script>',
    ));

    expect($this->fake->files['/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf'])
        ->toBe($originalServer);
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

it('renderer emits ungated analytics only in the server context when no gates are set', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
    ));

    expect($rendered->httpContext)->toBe('')
        ->and($rendered->serverContext)->toContain("sub_filter '</head>' '<script>x</script></head>';")
        ->and($rendered->serverContext)->not->toContain('$site_3075741_analytics_script');
});

it('renderer emits single-signal map in http and sub_filter in server when exactly one gate is on', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
        gateIsTargetPage: true,
    ));

    expect($rendered->httpContext)->toContain('map $site_3075741_is_target_page $site_3075741_analytics_script')
        ->and($rendered->httpContext)->not->toContain('sub_filter')
        ->and($rendered->serverContext)->toContain('sub_filter \'</head>\' $site_3075741_analytics_script;')
        ->and($rendered->serverContext)->not->toContain('map ')
        ->and($rendered->httpContext)->not->toContain('has_fbclid');
});

it('renderer emits a site-scoped $has_fbclid helper only when gateHasFbclid is on', function () {
    $with = (new ForgeSiteSettingsRenderer)->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
        gateHasFbclid: true,
    ));
    $without = (new ForgeSiteSettingsRenderer)->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
        gateIsTargetPage: true,
    ));

    expect($with->httpContext)->toContain('map $arg_fbclid $site_3075741_has_fbclid')
        ->and($without->httpContext)->not->toContain('has_fbclid');
});

it('renderer emits composite map with colon-joined signals when multiple gates are on', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
        gateNotBot: true,
        gateHasFbclid: true,
        gateIsTargetPage: true,
    ));

    expect($rendered->httpContext)->toContain('map "$is_bot:$site_3075741_has_fbclid:$site_3075741_is_target_page" $site_3075741_analytics_script')
        ->and($rendered->httpContext)->toContain('"0:1:1"');
});

it('renderer puts map directives only in http context and directives only in server context', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
        conditionalAccessLog: true,
        accessLogPath: '/var/log/nginx/site-fbclid.log',
        gateNotBot: true,
        gateHasFbclid: true,
    ));

    expect($rendered->httpContext)->toContain('map ')
        ->and($rendered->serverContext)->not->toContain('map ')
        ->and($rendered->serverContext)->toContain('access_log ')
        ->and($rendered->serverContext)->toContain('sub_filter');
});

it('parser round-trips a single-signal gated managed file', function () {
    $renderer = new ForgeSiteSettingsRenderer;
    $parser = new ForgeSiteSettingsParser;

    $original = new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>console.log("hi")</script>',
        conditionalAccessLog: true,
        accessLogPath: '/var/log/nginx/site-fbclid.log',
        gateIsTargetPage: true,
    );

    $parsed = $parser->parse($renderer->render('3075741', $original)->combined());

    expect($parsed->analyticsEnabled)->toBeTrue()
        ->and($parsed->gateIsTargetPage)->toBeTrue()
        ->and($parsed->gateNotBot)->toBeFalse()
        ->and($parsed->gateHasFbclid)->toBeFalse()
        ->and($parsed->gateIsTargetCountry)->toBeFalse()
        ->and($parsed->trackingTag)->toBe('</head>')
        ->and($parsed->scriptBody)->toBe('<script>console.log("hi")</script>')
        ->and($parsed->conditionalAccessLog)->toBeTrue()
        ->and($parsed->accessLogPath)->toBe('/var/log/nginx/site-fbclid.log');
});

it('parser round-trips a composite gated managed file', function () {
    $renderer = new ForgeSiteSettingsRenderer;
    $parser = new ForgeSiteSettingsParser;

    $original = new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
        gateNotBot: true,
        gateHasFbclid: true,
        gateIsTargetPage: true,
    );

    $parsed = $parser->parse($renderer->render('3075741', $original)->combined());

    expect($parsed->gateNotBot)->toBeTrue()
        ->and($parsed->gateHasFbclid)->toBeTrue()
        ->and($parsed->gateIsTargetCountry)->toBeFalse()
        ->and($parsed->gateIsTargetPage)->toBeTrue()
        ->and($parsed->scriptBody)->toBe('<script>x</script>');
});

it('save writes the http-context map file to conf.d when gates are on', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $this->registry->save(aForgeServer(), '3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
        gateHasFbclid: true,
    ));

    $httpFile = $this->fake->files['/etc/nginx/conf.d/redteam-forge-3075741.conf'] ?? null;
    $serverFile = $this->fake->files['/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf'] ?? null;

    expect($httpFile)->not->toBeNull()
        ->and($httpFile)->toContain('map $arg_fbclid $site_3075741_has_fbclid')
        ->and($httpFile)->toContain('map $site_3075741_has_fbclid $site_3075741_analytics_script')
        ->and($serverFile)->not->toBeNull()
        ->and($serverFile)->toContain('sub_filter \'</head>\' $site_3075741_analytics_script;')
        ->and($serverFile)->not->toContain('map ');
});

it('disable removes both http and server managed files', function () {
    $this->fake->withFile('/etc/nginx/conf.d/redteam-forge-3075741.conf', "# managed\n");
    $this->fake->withFile('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', "# managed\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $result = $this->registry->disable(aForgeServer(), '3075741');

    expect($result->ok)->toBeTrue()
        ->and(array_key_exists('/etc/nginx/conf.d/redteam-forge-3075741.conf', $this->fake->files))->toBeFalse()
        ->and(array_key_exists('/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf', $this->fake->files))->toBeFalse();
});

it('save cleans up stale http-context file when new settings have no gates', function () {
    $this->fake->withFile('/etc/nginx/conf.d/redteam-forge-3075741.conf', "# stale http\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $this->registry->save(aForgeServer(), '3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
    ));

    expect(array_key_exists('/etc/nginx/conf.d/redteam-forge-3075741.conf', $this->fake->files))->toBeFalse();
    expect($this->fake->files['/etc/nginx/forge-conf/3075741/server/redteam-analytics.conf'] ?? null)->not->toBeNull();
});

it('auto-wraps the script body in <script> tags when the user did not include any', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: "console.log('hi');",
    ));

    expect($rendered->serverContext)->toContain("sub_filter '</head>' '<script>console.log(\\'hi\\');</script></head>';");
});

it('leaves the script body alone when the user already provided a <script> tag', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script async src="https://cdn/x.js"></script>',
    ));

    expect($rendered->serverContext)->toContain("sub_filter '</head>' '<script async src=\"https://cdn/x.js\"></script></head>';");
});

it('writes managed backups outside the nginx include path', function () {
    seedRenderedSite($this->fake, '3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>old</script>',
    ));
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $this->registry->save(aForgeServer(), '3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>new</script>',
    ));

    $backupKeys = array_keys($this->fake->files);
    $managedDirBackups = array_filter($backupKeys, fn (string $p): bool => str_starts_with($p, '/etc/nginx/forge-conf/3075741/server/') && str_contains($p, '.bak.'));
    $redteamBackups = array_filter($backupKeys, fn (string $p): bool => str_starts_with($p, '/etc/nginx/redteam-backups/'));

    expect($managedDirBackups)->toBe([])
        ->and($redteamBackups)->not->toBe([]);
});

it('sweeps legacy in-place .bak files before writing a managed file', function () {
    $this->fake->shouldReturn(0, "/etc/nginx/forge-conf/3075741/site.conf\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $this->registry->save(aForgeServer(), '3075741', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
    ));

    $sweepHit = collect($this->fake->privilegedCommands)->contains(
        fn (array $p): bool => str_starts_with($p['command'], 'find ')
            && str_contains($p['command'], '/etc/nginx/forge-conf/3075741/server')
            && str_contains($p['command'], "'redteam-analytics.conf.bak.*'")
            && str_contains($p['command'], '-delete'),
    );

    expect($sweepHit)->toBeTrue();
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
