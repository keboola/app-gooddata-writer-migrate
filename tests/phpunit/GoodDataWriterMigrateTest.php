<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriterMigrate\Tests;

use function GuzzleHttp\Psr7\_parse_request_uri;
use Keboola\GoodDataWriterMigrate\GoodDataProjectMigrate;
use Keboola\GoodDataWriterMigrate\GoodDataWriterMigrate;
use Keboola\StorageApi\Client;
use PHPUnit\Framework\TestCase;

class GoodDataWriterMigrateTest extends TestCase
{

    public function testWriterMigrate(): void
    {
        $destinationProjectSapiClient = new Client([
            'url' => getenv('KBC_DESTINATION_PROJECT_URL'),
            'token' => getenv('KBC_DESTINATION_PROJECT_TOKEN'),
        ]);
        $sourceProjectSapiClient = new Client([
           'url' => getenv('KBC_SOURCE_PROJECT_URL'),
           'token' => getenv('KBC_SOURCE_PROJECT_TOKEN'),
        ]);

        $migrate = new GoodDataWriterMigrate(
            $destinationProjectSapiClient,
            parse_url($destinationProjectSapiClient->getApiUrl(), PHP_URL_HOST),
            parse_url($sourceProjectSapiClient->getApiUrl(), PHP_URL_HOST)
        );

        $migrate->migrateWriter([
            'id' => 'test_5',
            'name' => 'test name',
            'configuration' => [
                'user' => [
                    'login' => '',
                    'password' => '',
                    'uid' => '',
                ],
                'project' => [
                    'pid' => '',
                ],
            ],
        ]);
    }

    /**
     * @dataProvider mergeWriterConfigurationProvider
     * @param array $sourceConfiguration
     * @param array $destinationConfiguration
     * @param array $expectedConfiguration
     */
    public function testMergeWriterConfiguration(
        array $sourceConfiguration,
        array $destinationConfiguration,
        array $expectedConfiguration
    ): void {

        $this->assertEquals(
            $expectedConfiguration,
            GoodDataWriterMigrate::mergeDestinationConfiguration($sourceConfiguration, $destinationConfiguration)
        );
    }

    public function mergeWriterConfigurationProvider(): array
    {
        return [
          [
            [
                'user' => [
                    'login' => 'source_login',
                    'password' => 'source_password',
                    'uid' => 'source_uid',
                     'dummy' => 'dummy',
                ],
                'project' => [
                    'pid' => 'source_pid',
                ],
                'dimensions' => [
                    'Main' => [
                        'includeTime' => true,
                        'template' => 'gooddata',
                    ],
                ],
            ],
            [
              'user' => [
                  'login' => 'dest_login',
                  'password' => 'dest_password',
                  'uid' => 'dest_uid',

              ],
              'project' => [
                  'pid' => 'dest_pid',
              ],
            ],
            [
                'user' => [
                  'login' => 'dest_login',
                  'password' => 'dest_password',
                  'uid' => 'dest_uid',
                    'dummy' => 'dummy',
                ],
                'project' => [
                  'pid' => 'dest_pid',
                ],
                'dimensions' => [
                  'Main' => [
                      'includeTime' => true,
                      'template' => 'gooddata',
                  ],
                ],
            ],
          ],
        ];
    }
}
