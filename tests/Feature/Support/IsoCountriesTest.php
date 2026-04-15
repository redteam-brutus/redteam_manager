<?php

declare(strict_types=1);

use App\Support\IsoCountries;

it('returns the full ISO 3166-1 alpha-2 code set', function () {
    expect(IsoCountries::names())
        ->toHaveCount(249)
        ->toHaveKey('US')
        ->toHaveKey('IL')
        ->toHaveKey('GB');
});

it('resolves English country names via ext-intl', function () {
    $names = IsoCountries::names();

    expect($names['US'])->toBe('United States')
        ->and($names['IL'])->toBe('Israel')
        ->and($names['GB'])->toBe('United Kingdom');
});

it('formats options as "XX — Name" so both code and name are searchable', function () {
    $options = IsoCountries::options();

    expect($options['US'])->toBe('US — United States')
        ->and($options['IL'])->toBe('IL — Israel');
});
