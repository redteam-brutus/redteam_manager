<?php

declare(strict_types=1);

namespace App\Services\SiteLogs;

use DeviceDetector\DeviceDetector;

class UserAgentParser
{
    private const CACHE_MAX = 1000;

    private const EMPTY_RESULT = [
        'browser_name' => null,
        'browser_version' => null,
        'os_name' => null,
        'os_version' => null,
        'device_type' => null,
        'is_bot' => false,
    ];

    private ?DeviceDetector $detector = null;

    /** @var array<string, array{browser_name: ?string, browser_version: ?string, os_name: ?string, os_version: ?string, device_type: ?string, is_bot: bool}> */
    private array $cache = [];

    /**
     * @return array{browser_name: ?string, browser_version: ?string, os_name: ?string, os_version: ?string, device_type: ?string, is_bot: bool}
     */
    public function parse(?string $userAgent): array
    {
        $ua = trim((string) $userAgent);

        if ($ua === '') {
            return self::EMPTY_RESULT;
        }

        if (isset($this->cache[$ua])) {
            return $this->cache[$ua];
        }

        $detector = $this->detector();
        $detector->setUserAgent($ua);
        $detector->parse();

        if ($detector->isBot()) {
            $result = [
                'browser_name' => null,
                'browser_version' => null,
                'os_name' => null,
                'os_version' => null,
                'device_type' => null,
                'is_bot' => true,
            ];
        } else {
            $result = [
                'browser_name' => self::nullIfBlank($detector->getClient('name')),
                'browser_version' => self::nullIfBlank($detector->getClient('version')),
                'os_name' => self::nullIfBlank($detector->getOs('name')),
                'os_version' => self::nullIfBlank($detector->getOs('version')),
                'device_type' => self::nullIfBlank($detector->getDeviceName()),
                'is_bot' => false,
            ];
        }

        $this->remember($ua, $result);

        return $result;
    }

    private function detector(): DeviceDetector
    {
        if ($this->detector === null) {
            $this->detector = new DeviceDetector;
            $this->detector->discardBotInformation();
        }

        return $this->detector;
    }

    /**
     * @param  array{browser_name: ?string, browser_version: ?string, os_name: ?string, os_version: ?string, device_type: ?string, is_bot: bool}  $result
     */
    private function remember(string $ua, array $result): void
    {
        if (count($this->cache) >= self::CACHE_MAX) {
            array_shift($this->cache);
        }

        $this->cache[$ua] = $result;
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
