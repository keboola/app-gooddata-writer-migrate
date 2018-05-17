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

        $destinationSapiClient = new Client([
            'url' => getenv('TEST_DEST_KBC_URL'),
            'token' => getenv('TEST_DEST_KBC_TOKEN'),
        ]);
        $destinationSyrupUrl = Utils::getKeboolaServiceUrl(
            $destinationSapiClient->indexAction()['services'],
            GoodDataWriterMigrate::SYRUP_SERVICE_ID
        );
        $this->destinationGoodDataWriterClient = GoodDataWriterClientV2::factory([
            'url' => sprintf("%s/gooddata-writer", $destinationSyrupUrl),
            'token' => getenv('TEST_DEST_KBC_TOKEN'),
        ]);

        $this->temp = new Temp('app-gooddata-writer-migrate');
        $this->temp->initRunFolder();

        self::cleanupBuckets($this->sourceSapiClient);
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
        foreach ($client->listBuckets() as  $bucket) {
            $client->dropBucket($bucket['id'], [
                'force' => true,
            ]);
        }
    }

    public function testSuccessfulRun(): void
    {
        // prepare initials setup in project
        $sourceBucketId= $this->sourceSapiClient->createBucket(self::TEST_BUCKET_NAME, Client::STAGE_IN);
        $this->sourceSapiClient->createTableAsync(
            $sourceBucketId,
            self::TEST_TABLE_NAME,
            new CsvFile(__DIR__ . '/data/radio.csv')
        );

        $writerId = uniqid('test');
        $this->sourceGoodDataWriterClient->createWriter($writerId, [
            'authToken' => GoodDataWriterMigrate::WRITER_AUTH_TOKEN_DEMO,
        ]);
        $sourceWriter = $this->sourceGoodDataWriterClient->getWriter($writerId);

        // prepare config
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            \json_encode([
                'parameters' => [
                    'sourceKbcToken' => getenv('TEST_SOURCE_KBC_TOKEN'),
                    'sourceKbcUrl' => getenv('TEST_SOURCE_KBC_URL'),
                ],
            ])
        );

        // execute component
        $process = $this->createTestProcess();
        $process->mustRun();

        // check results
        $this->assertEquals(0, $process->getExitCode());
        $this->assertEmpty($process->getErrorOutput());

        $destWriter = $this->destinationGoodDataWriterClient->getWriter($writerId);
        $this->assertEquals($sourceWriter['project']['authToken'], $destWriter['project']['authToken']);
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
