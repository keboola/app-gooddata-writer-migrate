<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriterMigrate\Tests;

use Keboola\Csv\CsvFile;
use Keboola\GoodDataWriterMigrate\GoodDataWriterClientV2;
use Keboola\GoodDataWriterMigrate\GoodDataWriterMigrate;
use Keboola\GoodDataWriterMigrate\Utils;
use Keboola\StorageApi\Client;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FunctionalTest extends TestCase
{
    private const TEST_BUCKET_NAME = 'test';

    private const TEST_TABLE_NAME = 'test';

    /** @var GoodDataWriterClientV2 */
    private $sourceGoodDataWriterClient;

    /** @var GoodDataWriterClientV2 */
    private $destinationGoodDataWriterClient;

    /** @var Client */
    private $sourceSapiClient;

    /** @var Client */
    private $destinationSapiClient;

    /** @var Temp */
    private $temp;

    public function setUp(): void
    {
        $this->sourceSapiClient = new Client([
            'url' => getenv('TEST_SOURCE_KBC_URL'),
            'token' => getenv('TEST_SOURCE_KBC_TOKEN'),
        ]);
        $sourceSyrupUrl = Utils::getKeboolaServiceUrl(
            $this->sourceSapiClient->indexAction()['services'],
            GoodDataWriterMigrate::SYRUP_SERVICE_ID
        );
        $this->sourceGoodDataWriterClient = GoodDataWriterClientV2::factory([
            'url' => sprintf("%s/gooddata-writer", $sourceSyrupUrl),
            'token' => getenv('TEST_SOURCE_KBC_TOKEN'),
        ]);

        $this->destinationSapiClient = new Client([
            'url' => getenv('TEST_DEST_KBC_URL'),
            'token' => getenv('TEST_DEST_KBC_TOKEN'),
        ]);
        $destinationSyrupUrl = Utils::getKeboolaServiceUrl(
            $this->destinationSapiClient->indexAction()['services'],
            GoodDataWriterMigrate::SYRUP_SERVICE_ID
        );
        $this->destinationGoodDataWriterClient = GoodDataWriterClientV2::factory([
            'url' => sprintf("%s/gooddata-writer", $destinationSyrupUrl),
            'token' => getenv('TEST_DEST_KBC_TOKEN'),
        ]);

        $this->temp = new Temp('app-gooddata-writer-migrate');
        $this->temp->initRunFolder();

        self::cleanupBuckets($this->sourceSapiClient);
        self::cleanupBuckets($this->destinationSapiClient);
        self::cleanupGoodDataWriters($this->destinationGoodDataWriterClient);
        self::cleanupGoodDataWriters($this->sourceGoodDataWriterClient);
    }

    private static function cleanupGoodDataWriters(GoodDataWriterClientV2 $client): void
    {
        foreach ($client->getWriters() as $writer) {
            $client->deleteWriter($writer['id']);
        }
    }

    private static function cleanupBuckets(Client $client): void
    {
        foreach ($client->listBuckets() as $bucket) {
            $client->dropBucket($bucket['id'], [
                'force' => true,
            ]);
        }
    }

    public function testSuccessfulRun(): void
    {
        // prepare initials setup in project
        $sourceBucketId = $this->sourceSapiClient->createBucket(self::TEST_BUCKET_NAME, Client::STAGE_IN);
        $sourceTableId = $this->sourceSapiClient->createTableAsync(
            $sourceBucketId,
            self::TEST_TABLE_NAME,
            new CsvFile(__DIR__ . '/data/radio.csv')
        );
        // just create a same bucket and table in dest project to simulate migration of data
        // which is not handled by this component
        $destBucketId = $this->destinationSapiClient->createBucket(self::TEST_BUCKET_NAME, Client::STAGE_IN);
        $this->destinationSapiClient->createTableAsync(
            $destBucketId,
            self::TEST_TABLE_NAME,
            new CsvFile(__DIR__ . '/data/radio.csv')
        );

        // setup writer in source project
        $writerId = uniqid('test');
        $this->sourceGoodDataWriterClient->createWriter($writerId, [
            'authToken' => GoodDataWriterMigrate::WRITER_AUTH_TOKEN_DEMO,
        ]);
        $sourceWriter = $this->sourceGoodDataWriterClient->getWriter($writerId);

        $this->sourceGoodDataWriterClient->addWriterDateDimension(
            $writerId,
            [
                'name' => 'Keboola',
                'template' => 'keboola',
                'includeTime' => true,
            ]
        );

        $this->sourceGoodDataWriterClient->addWriterDateDimension(
            $writerId,
            [
                'name' => 'Gd',
                'template' => 'gooddata',
                'includeTime' => true,
            ]
        );

        $this->sourceGoodDataWriterClient->addTableToWriter(
            $writerId,
            $sourceTableId
        );
        $this->sourceGoodDataWriterClient->updateWriterTableConfiguration(
            $writerId,
            $sourceTableId,
            [
                'export' => true,
                'columns' => [
                    'id' => [
                        'type' => 'CONNECTION_POINT',
                        'name' => 'id',
                    ],
                    'text' => [
                        'type' => 'ATTRIBUTE',
                        'name' => 'text',
                    ],
                    'tag' => [
                        'type' => 'ATTRIBUTE',
                        'name' => 'tag',
                    ],
                ],
            ]
        );

        $this->sourceGoodDataWriterClient->updateWriterModel($writerId, $sourceWriter['project']['pid']);
        $this->sourceGoodDataWriterClient->loadWriterDataMulti($writerId, $sourceWriter['project']['pid']);
        $sourceWriterTables = $this->sourceGoodDataWriterClient->listWriterTables($writerId);
        $sourceWriterDateDimensions = $this->sourceGoodDataWriterClient->listWriterDateDimensions($writerId);

        // prepare config
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            \json_encode([
                'parameters' => [
                    '#sourceKbcToken' => getenv('TEST_SOURCE_KBC_TOKEN'),
                    'sourceKbcUrl' => getenv('TEST_SOURCE_KBC_URL'),
                ],
            ])
        );

        // perform migration
        $process = $this->createTestProcess();
        $process->mustRun();

        // check migration results
        $this->assertEquals(0, $process->getExitCode());
        $this->assertEmpty($process->getErrorOutput());

        $destWriter = $this->destinationGoodDataWriterClient->getWriter($writerId);
        $destWriterDateDimensions = $this->destinationGoodDataWriterClient->listWriterDateDimensions($writerId);
        $destWriterTables = $this->destinationGoodDataWriterClient->listWriterTables($writerId);

        $this->assertEquals($sourceWriter['project']['authToken'], $destWriter['project']['authToken']);
        $this->assertEquals('ready', $sourceWriter['status']);
        $this->assertEquals('ready', $destWriter['status']);
        $this->assertNotEquals($sourceWriter['project']['pid'], $destWriter['project']['pid']);
        $this->assertNotEquals($sourceWriter['user']['id'], $destWriter['project']['id']);

        $this->assertCount(2, $sourceWriterDateDimensions);
        $this->assertEquals($sourceWriterDateDimensions, $destWriterDateDimensions);

        $this->assertCount(1, $sourceWriterTables);
        $this->assertCount(1, $destWriterTables);
        $this->assertEquals($sourceTableId, $sourceWriterTables[0]['tableId']);
        $this->assertEquals($sourceTableId, $destWriterTables[0]['tableId']);
        $this->assertTrue($sourceWriterTables[0]['export']);
        $this->assertTrue($sourceWriterTables[0]['isExported']);
        $this->assertTrue($destWriterTables[0]['export']);
        $this->assertTrue($destWriterTables[0]['isExported']);
        $this->assertEquals($sourceWriterTables[0]['columns'], $destWriterTables[0]['columns']);
    }

    private function createTestProcess(): Process
    {
        $process = new Process('php /code/src/run.php');
        $process->setEnv([
            'KBC_DATADIR' => $this->temp->getTmpFolder(),
            'KBC_URL' => getenv('TEST_DEST_KBC_URL'),
            'KBC_TOKEN' => getenv('TEST_DEST_KBC_TOKEN'),
        ]);
        $process->setTimeout(null);
        return $process;
    }
}
