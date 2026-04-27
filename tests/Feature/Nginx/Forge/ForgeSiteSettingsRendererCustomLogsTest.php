<?php

declare(strict_types=1);

use App\Services\Nginx\Forge\Dto\ForgeSiteCustomLog;
use App\Services\Nginx\Forge\Dto\ForgeSiteSettings;
use App\Services\Nginx\Forge\ForgeSiteSettingsRenderer;

it('does not emit any per-log block when customLogs is empty', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('1', new ForgeSiteSettings(
        siteLoggingEnabled: true,
    ));

    // access_log + (no gate.log because no gates) baseline still present.
    expect($rendered->serverContext)->toContain('site-1-access.log');

    // No per-log artifacts.
    expect($rendered->serverContext)->not->toContain('_log_')
        ->and($rendered->httpContext)->not->toContain('_log_');
});

it('renders a single-signal hit map for a fbclid-only custom log', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('1', new ForgeSiteSettings(
        siteLoggingEnabled: true,
        customLogs: [
            new ForgeSiteCustomLog(slug: 'fb_only', requireFbclid: true),
        ],
    ));

    // Per-log access_log line.
    expect($rendered->serverContext)->toContain(
        'access_log /var/log/nginx/site-1-fb_only.log site_1_verbose if=$site_1_log_fb_only_hit;'
    );

    // Hit map references arg-only helper var (composite not required).
    expect($rendered->httpContext)->toContain('map $site_1_has_fbclid_arg $site_1_log_fb_only_hit');
    expect($rendered->httpContext)->not->toContain('site_1_has_fbclid_arg$site_1_has_social_referer');
});

it('renders a composite hit map for a multi-condition custom log', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('1', new ForgeSiteSettings(
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
    ));

    expect($rendered->httpContext)->toContain('map "$site_1_has_fbclid_arg:$site_1_is_target_country:$site_1_is_target_page" $site_1_log_fb_us_offer_hit');
    expect($rendered->httpContext)->toContain('"1:1:1" 1;');
});

it('emits a per-log override country map when overrideCountries is set', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('1', new ForgeSiteSettings(
        siteLoggingEnabled: true,
        targetCountries: ['DE'],
        gateIsTargetCountry: true,
        customLogs: [
            new ForgeSiteCustomLog(
                slug: 'us_only',
                requireTargetCountry: true,
                overrideCountries: ['US', 'CA'],
            ),
            new ForgeSiteCustomLog(
                slug: 'inherit',
                requireTargetCountry: true,
            ),
        ],
    ));

    // Per-log override map for us_only.
    expect($rendered->httpContext)->toContain('map $http_cf_ipcountry $site_1_log_us_only_is_target_country');
    expect($rendered->httpContext)->toContain('"US" 1;');
    expect($rendered->httpContext)->toContain('"CA" 1;');

    // us_only's hit map references the per-log var, not the site-level one.
    expect($rendered->httpContext)->toContain('map $site_1_log_us_only_is_target_country $site_1_log_us_only_hit');

    // inherit's hit map references the site-level var.
    expect($rendered->httpContext)->toContain('map $site_1_is_target_country $site_1_log_inherit_hit');
});

it('widens the helper-union when site-level gate is off but a custom log requires the helper', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('1', new ForgeSiteSettings(
        siteLoggingEnabled: true,
        gateIsTargetCountry: false,
        targetCountries: [], // no site-level list
        customLogs: [
            new ForgeSiteCustomLog(
                slug: 'override_only',
                requireTargetCountry: true,
                overrideCountries: ['IL'],
            ),
        ],
    ));

    // Site-level helper map NOT emitted (no inherit consumer).
    expect($rendered->httpContext)->not->toContain('map $http_cf_ipcountry $site_1_is_target_country');

    // Per-log override map IS emitted.
    expect($rendered->httpContext)->toContain('map $http_cf_ipcountry $site_1_log_override_only_is_target_country');
    expect($rendered->httpContext)->toContain('"IL" 1;');
});

it('widens helper-union for fbclid-arg even when site-level gateHasFbclid is off', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('1', new ForgeSiteSettings(
        siteLoggingEnabled: true,
        gateHasFbclid: false,
        socialRefererHosts: [],
        customLogs: [
            new ForgeSiteCustomLog(slug: 'fb', requireFbclid: true),
        ],
    ));

    expect($rendered->httpContext)->toContain('map $arg_fbclid $site_1_has_fbclid_arg');
    expect($rendered->httpContext)->not->toContain('$site_1_has_fbclid {');
});

it('emits an always-on hit map when no require flags are set', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('1', new ForgeSiteSettings(
        siteLoggingEnabled: true,
        customLogs: [
            new ForgeSiteCustomLog(slug: 'all_traffic'),
        ],
    ));

    expect($rendered->httpContext)->toContain('map $request_id $site_1_log_all_traffic_hit');
    expect($rendered->serverContext)->toContain(
        'access_log /var/log/nginx/site-1-all_traffic.log site_1_verbose if=$site_1_log_all_traffic_hit;'
    );
});

it('throws when slug is reserved', function () {
    (new ForgeSiteSettingsRenderer)->render('1', new ForgeSiteSettings(
        siteLoggingEnabled: true,
        customLogs: [
            new ForgeSiteCustomLog(slug: 'access'),
        ],
    ));
})->throws(InvalidArgumentException::class, "Custom log slug 'access' is reserved.");

it('throws when slug fails the regex', function () {
    (new ForgeSiteSettingsRenderer)->render('1', new ForgeSiteSettings(
        siteLoggingEnabled: true,
        customLogs: [
            new ForgeSiteCustomLog(slug: 'BadSlug'),
        ],
    ));
})->throws(InvalidArgumentException::class);

it('emits arg helper alongside simple form when both consumers want different vars', function () {
    $r = (new ForgeSiteSettingsRenderer)->render('1', new ForgeSiteSettings(
        siteLoggingEnabled: true,
        gateHasFbclid: true,
        socialRefererHosts: [],
        customLogs: [new ForgeSiteCustomLog(slug: 'fb', requireFbclid: true)],
    ));

    expect($r->httpContext)->toContain('$site_1_has_fbclid {');       // simple stays
    expect($r->httpContext)->toContain('$site_1_has_fbclid_arg {');   // arg also emitted
});

it('does not emit per-log blocks when siteLoggingEnabled is false', function () {
    $rendered = (new ForgeSiteSettingsRenderer)->render('1', new ForgeSiteSettings(
        analyticsEnabled: true,
        scriptBody: '<script>x</script>',
        siteLoggingEnabled: false,
        customLogs: [
            new ForgeSiteCustomLog(slug: 'fb', requireFbclid: true),
        ],
    ));

    expect($rendered->serverContext)->not->toContain('site-1-fb.log')
        ->and($rendered->httpContext)->not->toContain('_log_fb_hit');
});
