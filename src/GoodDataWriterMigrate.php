<?php

namespace Keboola\GoodDataWriterMigrate;

use Keboola\Component\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use \Keboola\GoodData\Client as GoodDataClient;
use Keboola\StorageApi\Options\Components\Configuration;

class GoodDataWriterMigrate
{
    private const GOOD_DATA_WRITER_COMPONENT_ID = 'gooddata-writer';

    private const GOOD_DATA_URL_MAP = [
      'connection.keboola.com' => 'https://secure.gooddata.com',
      'connection.eu-central-1.keboola.com' => 'https://keboola.eu.gooddata.com',
    ];

    /** @var Client  */
    private $destinationProjectSapiClient;

    /** @var string */
    private $sourceProjectStackId;

    /** @var string */
    private $destinationProjectStackId;

    public function __construct(
        Client $destinationProjectSapiClient,
        string $destinationProjectStackId,
        string $sourceProjectStackId
    )
    {
        $this->destinationProjectSapiClient = $destinationProjectSapiClient;
        $this->destinationProjectStackId = $destinationProjectStackId;
        $this->sourceProjectStackId = $sourceProjectStackId;
    }

    public function migrateWriter(array $sourceWriterConfiguration): void
    {
        $writerId = $sourceWriterConfiguration['id'];
        $this->createNewGoodDataWriterInDestinationProject($writerId);
        $destinationWriterConfiguration = $this->getCreateWriterRawConfiguration($writerId);

        $this->migrateGoodDataProject($sourceWriterConfiguration, $destinationWriterConfiguration);
        $this->updateDestinationConfigurationFromSource($sourceWriterConfiguration, $destinationWriterConfiguration);
    }

    private function updateDestinationConfigurationFromSource(array $sourceWriterConfiguration, array $destinationWriterConfiguration)
    {
        $components = new Components($this->destinationProjectSapiClient);
        $updatedConfigurationData = self::mergeDestinationConfiguration(
            $sourceWriterConfiguration['configuration'],
            $destinationWriterConfiguration['configuration']
        );
        $configOptions = new Configuration();
        $configOptions
            ->setComponentId(self::GOOD_DATA_WRITER_COMPONENT_ID)
            ->setConfigurationId($destinationWriterConfiguration['id'])
            ->setConfiguration($updatedConfigurationData);
        $components->updateConfiguration($configOptions);
    }

    public static function mergeDestinationConfiguration(array $sourceWriterConfiguration, array $destinationWriterConfiguration): array
    {
        return array_replace_recursive(
            $sourceWriterConfiguration,
            [
                'user' => [
                    'login' => $destinationWriterConfiguration['user']['login'],
                    'password' => $destinationWriterConfiguration['user']['password'],
                    'uid' => $destinationWriterConfiguration['user']['uid'],
                ],
                'project' => [
                    'pid' => $destinationWriterConfiguration['project']['pid'],
                ],
            ]
        );
    }

    private function migrateGoodDataProject(array $sourceWriterConfiguration, array $destinationWriterConfiguration): void
    {
        $sourceGoodDataClient = new GoodDataClient(
            $this->getGoodDataHostForKbcStack($this->sourceProjectStackId)
        );
        $sourceGoodDataClient->login(
            $sourceWriterConfiguration['configuration']['user']['login'],
            $sourceWriterConfiguration['configuration']['user']['password']
        );

        $destinationGoodDataClient = new GoodDataClient(
            $this->getGoodDataHostForKbcStack($this->destinationProjectStackId)
        );
        $destinationGoodDataClient->login(
            $destinationWriterConfiguration['configuration']['user']['login'],
            $destinationWriterConfiguration['configuration']['user']['password']
        );

        $goodDataMigrate = new GoodDataProjectMigrate();
        $goodDataMigrate->migrate(
            $sourceGoodDataClient,
            $sourceWriterConfiguration['project']['pid'],
            $destinationGoodDataClient,
            $destinationWriterConfiguration['project']['pid']
        );
    }

    private function createNewGoodDataWriterInDestinationProject(string $writerId): void
    {
        $goodDataWriterClient = \Keboola\Writer\GoodData\Client::factory([
            'url' => sprintf("%s/gooddata-writer", $this->getDestinationProjectSyrupUrl()),
            'token' => $this->destinationProjectSapiClient->getTokenString(),
        ]);
        $goodDataWriterClient->createWriter($writerId);
    }

    private function getCreateWriterRawConfiguration(string $writerId): array
    {
        $destinationComponents = new Components($this->destinationProjectSapiClient);
        return $destinationComponents->getConfiguration(
            self::GOOD_DATA_WRITER_COMPONENT_ID,
            $writerId
        );
    }

    private function getGoodDataHostForKbcStack(string $stackId): string
    {
        if (!isset(self::GOOD_DATA_URL_MAP[$stackId])) {
            throw new UserException(sprintf('GoodData host not found for stack %s', $stackId));
        }
        return self::GOOD_DATA_URL_MAP[$stackId];
    }

    private function getDestinationProjectSyrupUrl(): string
    {
        $services = $this->destinationProjectSapiClient->indexAction()['services'];

        $syrupServices = array_values(array_filter($services, function ($service) {
            return $service['id'] === 'syrup';
        }));
        if (empty($syrupServices)) {
            throw new \Exception('syrup service not found');
        }
        return $syrupServices[0]['url'];
    }
}
