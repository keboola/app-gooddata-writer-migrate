<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriterMigrate;

use Keboola\Component\BaseComponent;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;

class Component extends BaseComponent
{
    public function run(): void
    {
        /** @var Config $config */
        $config = $this->getConfig();
        $logger = $this->getLogger();

        $sourceProjectClient = $this->createStorageClient([
            'url' => $config->getSourceProjectUrl(),
            'token' => $config->getSourceProjectToken(),
        ]);
        $sourceTokenInfo = $sourceProjectClient->verifyToken();

        $destinationProjectClient = $this->createStorageClient([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);

        $writerMigrate = new GoodDataWriterMigrate(
            $destinationProjectClient,
            GoodDataWriterClientV2::createFromStorageClient($destinationProjectClient),
            GoodDataWriterClientV2::createFromStorageClient($sourceProjectClient),
            parse_url($destinationProjectClient->getApiUrl(), PHP_URL_HOST),
            parse_url($sourceProjectClient->getApiUrl(), PHP_URL_HOST)
        );

        $logger->info(sprintf(
            'Migrating GoodData writers from project %s (%d)',
            $sourceTokenInfo['owner']['name'],
            $sourceTokenInfo['owner']['id']
        ));

        $writersToMigrate = (new Components($sourceProjectClient))->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())
                ->setComponentId(GoodDataWriterMigrate::GOOD_DATA_WRITER_COMPONENT_ID)
        );

        foreach ($writersToMigrate as $writerConfigurationWithRows) {
            $logger->info(sprintf('Writer %s migration started', $writerConfigurationWithRows['id']));
            $writerMigrate->migrateWriter($writerConfigurationWithRows);
            $logger->info(sprintf('Writer %s migration done', $writerConfigurationWithRows['id']));
        }
    }

    private function createStorageClient(array $params): Client
    {
        $client = new Client($params);
        $client->setRunId($this->getKbcRunId());
        return $client;
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    protected function getConfigDefinitionClass(): string
    {
        return ConfigDefinition::class;
    }

    private function getKbcRunId(): string
    {
        return (string) getenv('KBC_RUNID');
    }
}
