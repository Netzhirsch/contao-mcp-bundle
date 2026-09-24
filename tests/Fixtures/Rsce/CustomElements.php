<?php

declare(strict_types=1);

namespace MadeYourDay\RockSolidCustomElements;

/**
 * Test double for RSCE's CustomElements — only the static method this bundle
 * calls. The RSCE tests load it explicitly; the test autoloader does not map
 * this namespace, and nothing under tests/ is ever shipped.
 *
 * With it loaded, class_exists() answers "RSCE is installed", which is what
 * RsceElements asks.
 */
final class CustomElements
{
    /** @var array<string, array<string, mixed>|null> type => config, as rsce_*_config.php would return it */
    public static array $configs = [];

    public static int $lookups = 0;

    /**
     * @return array<string, mixed>|null
     */
    public static function getConfigByType(string $type): ?array
    {
        ++self::$lookups;

        return self::$configs[$type] ?? null;
    }
}
