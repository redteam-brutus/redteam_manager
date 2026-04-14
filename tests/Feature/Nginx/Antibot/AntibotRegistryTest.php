<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Nginx\Antibot\AntibotRegistry;
use App\Services\Nginx\Antibot\AntibotSettingsParser;
use App\Services\Nginx\Antibot\AntibotSettingsRenderer;
use App\Services\Nginx\Antibot\Dto\AntibotSettings;
use App\Services\Ssh\Contracts\SshClient;
use App\Services\Ssh\Testing\FakeSshClient;

beforeEach(function () {
    $this->fake = new FakeSshClient;
    $this->fake->withHostFingerprint('fingerprint-known');
    $this->app->instance(SshClient::class, $this->fake);
    $this->registry = app(AntibotRegistry::class);
});

function anAntibotServer(): Server
{
    return Server::factory()->create([
        'host_fingerprint' => 'fingerprint-known',
        'use_sudo' => true,
        'sudo_password' => 'hunter2',
    ]);
}

it('load returns empty settings when no managed file exists', function () {
    $settings = $this->registry->load(anAntibotServer());

    expect($settings->isEmpty())->toBeTrue();
});

it('renderer round-trips through the parser', function () {
    $renderer = new AntibotSettingsRenderer;
    $parser = new AntibotSettingsParser;

    $settings = new AntibotSettings(
        botPatterns: ['googlebot', 'bingbot', 'yandex'],
        targetCountries: ['IL', 'EG'],
        targetPages: ['^/page-1/', '^/another-page/'],
    );

    $parsed = $parser->parse($renderer->render($settings));

    expect($parsed->botPatterns)->toBe($settings->botPatterns)
        ->and($parsed->targetCountries)->toBe($settings->targetCountries)
        ->and($parsed->targetPages)->toBe($settings->targetPages);
});

it('parser tolerates a file with only one map block', function () {
    $parser = new AntibotSettingsParser;
    $content = <<<'EOT'
map $http_user_agent $is_bot {
    default 0;
    ~*(googlebot) 1;
}
EOT;

    $settings = $parser->parse($content);

    expect($settings->botPatterns)->toBe(['googlebot'])
        ->and($settings->targetCountries)->toBe([])
        ->and($settings->targetPages)->toBe([]);
});

it('save writes the managed file via sudo mv', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $settings = new AntibotSettings(
        botPatterns: ['googlebot'],
        targetCountries: ['IL'],
        targetPages: ['^/page-1/'],
    );

    $result = $this->registry->save(anAntibotServer(), $settings);

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe('saved');

    $managed = $this->fake->files['/etc/nginx/conf.d/redteam-antibot.conf'] ?? null;
    expect($managed)->not->toBeNull()
        ->and($managed)->toContain('~*(googlebot) 1;')
        ->and($managed)->toContain('"IL" 1;')
        ->and($managed)->toContain('"~*^/page-1/" 1;');
});

it('save removes a brand-new file when nginx -t fails', function () {
    $this->fake->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] bogus\n");

    $result = $this->registry->save(anAntibotServer(), new AntibotSettings(botPatterns: ['googlebot']));

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe('invalid_config');

    expect(array_key_exists('/etc/nginx/conf.d/redteam-antibot.conf', $this->fake->files))
        ->toBeFalse();
});

it('save restores the previous managed file when nginx -t fails', function () {
    $renderer = new AntibotSettingsRenderer;
    $originalContent = $renderer->render(new AntibotSettings(botPatterns: ['googlebot']));
    $this->fake->withFile('/etc/nginx/conf.d/redteam-antibot.conf', $originalContent);

    $this->fake->shouldReturnForCommand('nginx -t', 1, "nginx: [emerg] bogus\n");

    $this->registry->save(anAntibotServer(), new AntibotSettings(botPatterns: ['newbot']));

    expect($this->fake->files['/etc/nginx/conf.d/redteam-antibot.conf'])->toBe($originalContent);
});

it('save with all-empty settings routes to disable', function () {
    $this->fake->withFile('/etc/nginx/conf.d/redteam-antibot.conf', "# managed\n");
    $this->fake->shouldReturnForCommand('nginx -t', 0, "ok\n");

    $result = $this->registry->save(anAntibotServer(), new AntibotSettings);

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe('saved');

    expect(array_key_exists('/etc/nginx/conf.d/redteam-antibot.conf', $this->fake->files))
        ->toBeFalse();
});

it('disable is a no-op when no managed file exists', function () {
    $result = $this->registry->disable(anAntibotServer());

    expect($result->ok)->toBeTrue()
        ->and($result->output)->toBe('Already disabled.');
});

it('renderer rejects an invalid country code', function () {
    (new AntibotSettingsRenderer)->render(new AntibotSettings(targetCountries: ['israel']));
})->throws(InvalidArgumentException::class, 'Country code');

it('renderer rejects a bot pattern containing a pipe', function () {
    (new AntibotSettingsRenderer)->render(new AntibotSettings(botPatterns: ['good|bad']));
})->throws(InvalidArgumentException::class, 'Bot pattern contains forbidden chars');

it('renderer rejects a target page containing a newline', function () {
    (new AntibotSettingsRenderer)->render(new AntibotSettings(targetPages: ["^/a/\n"]));
})->throws(InvalidArgumentException::class, 'Target page body');
