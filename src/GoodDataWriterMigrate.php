<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriterMigrate;

use Guzzle\Http\Exception\ClientErrorResponseException;
use Keboola\Component\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use \Keboola\GoodData\Client as GoodDataClient;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;

class GoodDataWriterMigrate
{
    public const GOOD_DATA_WRITER_COMPONENT_ID = 'gooddata-writer';

    public const SYRUP_SERVICE_ID = 'syrup';

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
    ) {
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
        $this->copyConfigurationRowsFromSource($writerId, $sourceWriterConfiguration['rows']);
    }

    private function copyConfigurationRowsFromSource(string $destinationConfigId, array $sourceWriterConfigRows): void
    {
        $components = new Components($this->destinationProjectSapiClient);
        $destinationConfiguration = new Configuration();
        $destinationConfiguration
            ->setComponentId(self::GOOD_DATA_WRITER_COMPONENT_ID)
            ->setConfigurationId($destinationConfigId);

        foreach ($sourceWriterConfigRows as $row) {
            $configurationRow = new ConfigurationRow($destinationConfiguration);
            $configurationRow
                ->setConfiguration($row['configuration'])
                ->setRowId($row['id'])
                ->setName($row['name'])
                ->setDescription($row['description'])
                ->setIsDisabled($row['isDisabled']);
            $components->addConfigurationRow($configurationRow);
        }
    }

    private function updateDestinationConfigurationFromSource(
        array $sourceWriterConfiguration,
        array $destinationWriterConfiguration
    ): void {
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

    public static function mergeDestinationConfiguration(
        array $sourceWriterConfiguration,
        array $destinationWriterConfiguration
    ): array {
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

    private function migrateGoodDataProject(
        array $sourceWriterConfiguration,
        array $destinationWriterConfiguration
    ): void {
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
            $sourceWriterConfiguration['configuration']['project']['pid'],
            $destinationGoodDataClient,
            $destinationWriterConfiguration['configuration']['project']['pid'],
            $destinationWriterConfiguration['configuration']['user']['login']
        );
    }

    private function createNewGoodDataWriterInDestinationProject(string $writerId): void
    {
        $goodDataWriterClient = \Keboola\Writer\GoodData\Client::factory([
            'url' => sprintf("%s/gooddata-writer", $this->getDestinationProjectSyrupUrl()),
            'token' => $this->destinationProjectSapiClient->getTokenString(),
        ]);
        try {
            $goodDataWriterClient->createWriter($writerId);
        } catch (ClientErrorResponseException $e) {
            throw new UserException(
                sprintf('Cannot create writer: %s', (string) $e->getResponse()->getBody())
            );
        }
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
        return Utils::getKeboolaServiceUrl(
            $this->destinationProjectSapiClient->indexAction()['services'],
            self::SYRUP_SERVICE_ID
        );
    }
}
