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

    public const WRITER_AUTH_TOKEN_DEMO = 'keboola_demo';

    public const WRITER_AUTH_TOKEN_PRODUCTION = 'keboola_production';

    private const GOOD_DATA_URL_MAP = [
      'connection.keboola.com' => 'https://secure.gooddata.com',
      'connection.eu-central-1.keboola.com' => 'https://keboola.eu.gooddata.com',
    ];

    /** @var Client  */
    private $destinationProjectSapiClient;

    /** @var Client  */
    private $sourceProjectSapiClient;

    /** @var string */
    private $sourceProjectStackId;

    /** @var string */
    private $destinationProjectStackId;

    public function __construct(
        Client $destinationProjectSapiClient,
        Client $sourceProjectSapiClient,
        string $destinationProjectStackId,
        string $sourceProjectStackId
    ) {
        $this->destinationProjectSapiClient = $destinationProjectSapiClient;
        $this->sourceProjectSapiClient = $sourceProjectSapiClient;
        $this->destinationProjectStackId = $destinationProjectStackId;
        $this->sourceProjectStackId = $sourceProjectStackId;
    }

    public function migrateWriter(array $sourceWriterSapiConfiguration): void
    {
        $writerId = $sourceWriterSapiConfiguration['id'];
        $sourceWriterConfiguration = $this->getSourceWriterConfiguration($writerId);

        $this->createNewGoodDataWriterInDestinationProject(
            $writerId,
            $sourceWriterConfiguration['project']['authToken']
        );
        $destinationWriterConfiguration = $this->getCreatedWriterSapiConfiguration($writerId);

        $this->migrateGoodDataProject($sourceWriterSapiConfiguration, $destinationWriterConfiguration);
        $this->updateDestinationConfigurationFromSource(
            $sourceWriterSapiConfiguration,
            $destinationWriterConfiguration
        );
        $this->copyConfigurationRowsFromSource($writerId, $sourceWriterSapiConfiguration['rows']);
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
        array $sourceWriterSapiConfiguration,
        array $destinationWriterSapiConfiguration
    ): void {
        $components = new Components($this->destinationProjectSapiClient);
        $updatedConfigurationData = self::mergeDestinationConfiguration(
            $sourceWriterSapiConfiguration['configuration'],
            $destinationWriterSapiConfiguration['configuration']
        );
        $configOptions = new Configuration();
        $configOptions
            ->setComponentId(self::GOOD_DATA_WRITER_COMPONENT_ID)
            ->setConfigurationId($destinationWriterSapiConfiguration['id'])
            ->setConfiguration($updatedConfigurationData);
        $components->updateConfiguration($configOptions);
    }

    public static function mergeDestinationConfiguration(
        array $sourceWriterSapiConfiguration,
        array $destinationWriterSapiConfiguration
    ): array {
        return array_replace_recursive(
            $sourceWriterSapiConfiguration,
            [
                'user' => [
                    'login' => $destinationWriterSapiConfiguration['user']['login'],
                    'password' => $destinationWriterSapiConfiguration['user']['password'],
                    'uid' => $destinationWriterSapiConfiguration['user']['uid'],
                ],
                'project' => [
                    'pid' => $destinationWriterSapiConfiguration['project']['pid'],
                ],
            ]
        );
    }

    private function migrateGoodDataProject(
        array $sourceWriterSapiConfiguration,
        array $destinationWriterSapiConfiguration
    ): void {
        $sourceGoodDataClient = new GoodDataClient(
            $this->getGoodDataHostForKbcStack($this->sourceProjectStackId)
        );
        $sourceGoodDataClient->login(
            $sourceWriterSapiConfiguration['configuration']['user']['login'],
            $sourceWriterSapiConfiguration['configuration']['user']['password']
        );

        $destinationGoodDataClient = new GoodDataClient(
            $this->getGoodDataHostForKbcStack($this->destinationProjectStackId)
        );
        $destinationGoodDataClient->login(
            $destinationWriterSapiConfiguration['configuration']['user']['login'],
            $destinationWriterSapiConfiguration['configuration']['user']['password']
        );

        $goodDataMigrate = new GoodDataProjectMigrate();
        $goodDataMigrate->migrate(
            $sourceGoodDataClient,
            $sourceWriterSapiConfiguration['configuration']['project']['pid'],
            $destinationGoodDataClient,
            $destinationWriterSapiConfiguration['configuration']['project']['pid'],
            $destinationWriterSapiConfiguration['configuration']['user']['login']
        );
    }

    private function createNewGoodDataWriterInDestinationProject(string $writerId, string $authToken): void
    {
        $goodDataWriterClient = GoodDataWriterClientV2::factory([
            'url' => sprintf("%s/gooddata-writer", $this->getDestinationProjectSyrupUrl()),
            'token' => $this->destinationProjectSapiClient->getTokenString(),
        ]);
        try {
            $goodDataWriterClient->createWriter($writerId, [
                'authToken' => $authToken,
            ]);
        } catch (ClientErrorResponseException $e) {
            throw new UserException(
                sprintf('Cannot create writer: %s', (string) $e->getResponse()->getBody())
            );
        }
    }

    private function getSourceWriterConfiguration(string $writerId): array
    {
        $goodDataWriterClient = GoodDataWriterClientV2::factory([
            'url' => sprintf("%s/gooddata-writer", $this->getSourceProjectSyrupUrl()),
            'token' => $this->sourceProjectSapiClient->getTokenString(),
        ]);
        return $goodDataWriterClient->getWriter($writerId);
    }

    private function getCreatedWriterSapiConfiguration(string $writerId): array
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

    private function getSourceProjectSyrupUrl():string
    {
        return Utils::getKeboolaServiceUrl(
            $this->sourceProjectSapiClient->indexAction()['services'],
            self::SYRUP_SERVICE_ID
        );
    }
}
