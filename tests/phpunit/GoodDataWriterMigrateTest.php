<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriterMigrate\Tests;

use Keboola\GoodDataWriterMigrate\GoodDataWriterMigrate;
use PHPUnit\Framework\TestCase;

class GoodDataWriterMigrateTest extends TestCase
{
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
