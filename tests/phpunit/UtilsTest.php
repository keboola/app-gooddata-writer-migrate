<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriterMigrate\Tests;

use Keboola\GoodDataWriterMigrate\Utils;
use PHPUnit\Framework\TestCase;

class UtilsTest extends TestCase
{

    /**
     * @dataProvider serviceUrlDataProvider
     * @param array $services
     * @param string $serviceId
     * @param string $expectedServiceUrl
     * @throws \Exception
     */
    public function testGetKeboolaServiceUrlFound(array $services, string $serviceId, string $expectedServiceUrl): void
    {
        $foundServiceUrl = Utils::getKeboolaServiceUrl($services, $serviceId);
        $this->assertEquals($expectedServiceUrl, $foundServiceUrl);
    }

    public function serviceUrlDataProvider(): array
    {
        return [
            [
                [
                    [
                        'id' => 'docker-runner',
                        'url' => 'https://docker-runner.keboola.com',
                    ],
                    [
                        'id' => 'syrup',
                        'url' => 'https://syrup.keboola.com',
                    ],
                ],
                'syrup',
                'https://syrup.keboola.com',
            ],
        ];
    }
}
