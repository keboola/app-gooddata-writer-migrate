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

    public const WRITER_AUTH_TOKEN_DEMO = 'keboola_demo';

    public const WRITER_AUTH_TOKEN_PRODUCTION = 'keboola_production';

    private const GOOD_DATA_URL_MAP = [
      'connection.keboola.com' => 'https://secure.gooddata.com',
      'connection.eu-central-1.keboola.com' => 'https://keboola.eu.gooddata.com',
    ];

    /** @var Client  */
    private $destinationProjectSapiClient;

    /** @var GoodDataWriterClientV2 */
    private $sourceProjecGoodDataWriterClient;

    /** @var GoodDataWriterClientV2 */
    private $destinationProjectGoodDataWriterClient;

    /** @var string */
    private $sourceProjectStackId;

    /** @var string */
    private $destinationProjectStackId;

    public function __construct(
        Client $destinationProjectSapiClient,
        GoodDataWriterClientV2 $destinationProjectGoodDataWriterClient,
        GoodDataWriterClientV2 $sourceProjectGoodDataWriterClient,
        string $destinationProjectStackId,
        string $sourceProjectStackId
    ) {
        $this->destinationProjectSapiClient = $destinationProjectSapiClient;
        $this->destinationProjectGoodDataWriterClient = $destinationProjectGoodDataWriterClient;
        $this->sourceProjecGoodDataWriterClient = $sourceProjectGoodDataWriterClient;
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
        try {
            $this->destinationProjectGoodDataWriterClient->createWriter($writerId, [
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
        return $this->sourceProjecGoodDataWriterClient->getWriter($writerId);
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
}
