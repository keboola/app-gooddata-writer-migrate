<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriterMigrate\Tests;

use Keboola\GoodDataWriterMigrate\GoodDataWriterMigrate;
use Keboola\GoodDataWriterMigrate\Utils;
use Keboola\StorageApi\Client;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FunctionalTest extends TestCase
{
    /** @var \Keboola\Writer\GoodData\Client */
    private $sourceGoodDataWriterClient;

    /** @var \Keboola\Writer\GoodData\Client */
    private $destinationGoodDataWriterClient;

    /** @var Temp */
    private $temp;

    public function setUp(): void
    {
        $sourceSapiClient = new Client([
            'url' => getenv('TEST_SOURCE_KBC_URL'),
            'token' => getenv('TEST_SOURCE_KBC_TOKEN'),
        ]);
        $sourceSyrupUrl = Utils::getKeboolaServiceUrl(
            $sourceSapiClient->indexAction()['services'],
            GoodDataWriterMigrate::SYRUP_SERVICE_ID
        );
        $this->sourceGoodDataWriterClient = \Keboola\Writer\GoodData\Client::factory([
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
        $this->destinationGoodDataWriterClient = \Keboola\Writer\GoodData\Client::factory([
            'url' => sprintf("%s/gooddata-writer", $destinationSyrupUrl),
            'token' => getenv('TEST_DEST_KBC_TOKEN'),
        ]);

        $this->temp = new Temp('app-gooddata-writer-migrate');
        $this->temp->initRunFolder();

        self::cleanupGoodDataWriters($this->destinationGoodDataWriterClient);
        self::cleanupGoodDataWriters($this->sourceGoodDataWriterClient);
    }

    private static function cleanupGoodDataWriters(\Keboola\Writer\GoodData\Client $client): void
    {
        foreach ($client->getWriters() as $writer) {
            $client->deleteWriter($writer['id']);
        }
    }

    public function testSuccessfulRun(): void
    {
        $writerId = uniqid('test');
        $this->sourceGoodDataWriterClient->createWriter($writerId);

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

        $process = $this->createTestProcess();
        $process->mustRun();

        $this->assertEquals(0, $process->getExitCode());
        $this->assertEmpty($process->getErrorOutput());
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
