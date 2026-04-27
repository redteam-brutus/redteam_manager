<?php

declare(strict_types=1);

use App\Services\Nginx\Forge\Dto\ForgeSiteCustomLog;
use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;
use App\Services\Nginx\Forge\ForgeSiteSettingsParser;
use App\Services\Nginx\Forge\ForgeSiteSettingsRenderer;

function renderForParse(ForgeSiteSettings $settings, string $siteId = '1'): string
{
    $rendered = (new ForgeSiteSettingsRenderer)->render($siteId, $settings);

    return $rendered->httpContext."\n".$rendered->serverContext;
}

it('round-trips zero custom logs (regression guard)', function () {
    $settings = new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
        siteLoggingEnabled: true,
    );

    $content = renderForParse($settings);
    $parsed = (new ForgeSiteSettingsParser)->parse($content);

    expect($parsed->customLogs)->toBe([]);
});

it('round-trips a single-condition custom log', function () {
    $settings = new ForgeSiteSettings(
        siteLoggingEnabled: true,
        customLogs: [
            new ForgeSiteCustomLog(slug: 'fb_only', requireFbclid: true),
        ],
    );

    $parsed = (new ForgeSiteSettingsParser)->parse(renderForParse($settings));

    expect($parsed->customLogs)->toHaveCount(1);
    expect($parsed->customLogs[0]->slug)->toBe('fb_only');
    expect($parsed->customLogs[0]->requireFbclid)->toBeTrue();
    expect($parsed->customLogs[0]->requireSocialReferer)->toBeFalse();
    expect($parsed->customLogs[0]->overrideCountries)->toBeNull();
});

it('round-trips a multi-condition custom log', function () {
    $settings = new ForgeSiteSettings(
        siteLoggingEnabled: true,
        targetCountries: ['US'],
        targetPages: ['^/offer/'],
        customLogs: [
            new ForgeSiteCustomLog(
                slug: 'fb_us_offer',
                requireFbclid: true,
                requireTargetCountry: true,
                requireTargetPage: true,
            ),
        ],
    );

    $parsed = (new ForgeSiteSettingsParser)->parse(renderForParse($settings));

    expect($parsed->customLogs)->toHaveCount(1);
    $log = $parsed->customLogs[0];
    expect($log->slug)->toBe('fb_us_offer');
    expect($log->requireFbclid)->toBeTrue();
    expect($log->requireTargetCountry)->toBeTrue();
    expect($log->requireTargetPage)->toBeTrue();
    expect($log->overrideCountries)->toBeNull();
    expect($log->overridePages)->toBeNull();
});

it('round-trips per-log overrides', function () {
    $settings = new ForgeSiteSettings(
        siteLoggingEnabled: true,
        targetCountries: ['DE'],
        gateIsTargetCountry: true,
        customLogs: [
            new ForgeSiteCustomLog(
                slug: 'us_ca',
                requireTargetCountry: true,
                overrideCountries: ['US', 'CA'],
            ),
            new ForgeSiteCustomLog(
                slug: 'inherit',
                requireTargetCountry: true,
            ),
        ],
    );

    $parsed = (new ForgeSiteSettingsParser)->parse(renderForParse($settings));

    expect($parsed->customLogs)->toHaveCount(2);

    $byslug = collect($parsed->customLogs)->keyBy('slug');

    expect($byslug['us_ca']->overrideCountries)->toBe(['US', 'CA']);
    expect($byslug['us_ca']->requireTargetCountry)->toBeTrue();
    expect($byslug['inherit']->overrideCountries)->toBeNull();
    expect($byslug['inherit']->requireTargetCountry)->toBeTrue();
});

it('round-trips a custom log with social referer override', function () {
    $settings = new ForgeSiteSettings(
        siteLoggingEnabled: true,
        customLogs: [
            new ForgeSiteCustomLog(
                slug: 'social_alt',
                requireSocialReferer: true,
                overrideSocialRefererHosts: ['example.com', 'other.example'],
            ),
        ],
    );

    $parsed = (new ForgeSiteSettingsParser)->parse(renderForParse($settings));

    expect($parsed->customLogs)->toHaveCount(1);
    expect($parsed->customLogs[0]->requireSocialReferer)->toBeTrue();
    expect($parsed->customLogs[0]->overrideSocialRefererHosts)->toBe(['example.com', 'other.example']);
});

it('round-trips composite fbclid+socialReferer requirement', function () {
    $settings = new ForgeSiteSettings(
        siteLoggingEnabled: true,
        gateHasFbclid: true,
        socialRefererHosts: ['facebook.com'],
        customLogs: [
            new ForgeSiteCustomLog(
                slug: 'fb_combined',
                requireFbclid: true,
                requireSocialReferer: true,
            ),
        ],
    );

    $parsed = (new ForgeSiteSettingsParser)->parse(renderForParse($settings));

    expect($parsed->customLogs)->toHaveCount(1);
    expect($parsed->customLogs[0]->requireFbclid)->toBeTrue();
    expect($parsed->customLogs[0]->requireSocialReferer)->toBeTrue();
});

it('round-trips site-level gates when analytics is disabled', function () {
    $settings = new ForgeSiteSettings(
        siteLoggingEnabled: true,
        gateNotBot: true,
        gateHasFbclid: true,
        gateIsTargetCountry: true,
        gateIsTargetPage: true,
        targetCountries: ['US', 'IL'],
        targetPages: ['^/offer/'],
        socialRefererHosts: ['facebook.com'],
    );

    $parsed = (new ForgeSiteSettingsParser)->parse(renderForParse($settings));

    expect($parsed->gateNotBot)->toBeTrue();
    expect($parsed->gateHasFbclid)->toBeTrue();
    expect($parsed->gateIsTargetCountry)->toBeTrue();
    expect($parsed->gateIsTargetPage)->toBeTrue();
});

it('round-trips a single site-level gate when analytics is disabled', function () {
    $settings = new ForgeSiteSettings(
        siteLoggingEnabled: true,
        gateHasFbclid: true,
    );

    $parsed = (new ForgeSiteSettingsParser)->parse(renderForParse($settings));

    expect($parsed->gateHasFbclid)->toBeTrue();
    expect($parsed->gateNotBot)->toBeFalse();
    expect($parsed->gateIsTargetCountry)->toBeFalse();
    expect($parsed->gateIsTargetPage)->toBeFalse();
});

it('round-trips composite fbclid+socialReferer with override hosts', function () {
    $settings = new ForgeSiteSettings(
        siteLoggingEnabled: true,
        customLogs: [
            new ForgeSiteCustomLog(
                slug: 'fb_alt',
                requireFbclid: true,
                requireSocialReferer: true,
                overrideSocialRefererHosts: ['alt.example'],
            ),
        ],
    );

    $parsed = (new ForgeSiteSettingsParser)->parse(renderForParse($settings));

    expect($parsed->customLogs)->toHaveCount(1);
    $log = $parsed->customLogs[0];
    expect($log->requireFbclid)->toBeTrue();
    expect($log->requireSocialReferer)->toBeTrue();
    expect($log->overrideSocialRefererHosts)->toBe(['alt.example']);
});

it('round-trips an always-on custom log', function () {
    $settings = new ForgeSiteSettings(
        siteLoggingEnabled: true,
        customLogs: [
            new ForgeSiteCustomLog(slug: 'all_traffic'),
        ],
    );

    $parsed = (new ForgeSiteSettingsParser)->parse(renderForParse($settings));

    expect($parsed->customLogs)->toHaveCount(1);
    expect($parsed->customLogs[0]->slug)->toBe('all_traffic');
    expect($parsed->customLogs[0]->hasAnyRequirement())->toBeFalse();
});
