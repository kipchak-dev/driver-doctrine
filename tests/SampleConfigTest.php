<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/vendor/kipchak/core/src/Functions.php';

/**
 * The sample configuration, which projects copy, turns dev_mode on only when DEBUG asks for it. dev_mode replaces the
 * configured metadata cache with an in-memory one in each worker, so it must not be on in production by default.
 */
final class SampleConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('DEBUG');
    }

    /**
     * @return array<string, array{?string, bool}>
     */
    public static function values(): array
    {
        return [
            'unset' => [null, false],
            'empty' => ['', false],
            'false' => ['false', false],
            '0' => ['0', false],
            'off' => ['off', false],
            'a non-boolean value' => ['verbose', false],
            'true' => ['true', true],
            '1' => ['1', true],
            'yes' => ['yes', true],
            'on' => ['on', true],
        ];
    }

    #[DataProvider('values')]
    public function testDevModeFollowsTheDebugVariable(?string $value, bool $devMode): void
    {
        putenv($value === null ? 'DEBUG' : "DEBUG={$value}");
        $config = require dirname(__DIR__) . '/sample.config.php';

        self::assertSame($devMode, $config['orm']['entity_managers']['primary']['dev_mode']);
    }
}
