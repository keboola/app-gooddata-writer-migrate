<?php

namespace Keboola\GoodDataWriterMigrate\Tests;


use Keboola\GoodDataWriterMigrate\GoodDataWriterMigrate;
use Keboola\StorageApi\Client;
use PHPUnit\Framework\TestCase;

class GoodDataWriterMigrateTest extends TestCase
{

    public function testWriterMigrate()
    {
        $destinationProjectSapiClient = new Client([
           'url' => getenv('KBC_DESTINATION_PROJECT_URL'),
           'token' => getenv('KBC_DESTINATION_PROJECT_TOKEN'),
        ]);

        $migrate = new GoodDataWriterMigrate($destinationProjectSapiClient);

        $migrate->migrateWriter([
            'id' => 'test_2',
            'name' => 'test name',
            'configuration' => [
                'user' => [
                    'login' => '',
                    'password' => '',
                    'uid' => ''
                ],
                'project' => [
                  'pid' => '',
                ],
            ],
        ]);
    }
}
