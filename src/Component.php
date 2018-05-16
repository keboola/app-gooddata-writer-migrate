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
        $sourceProjectClient = new Client([
            'url' => $config->getSourceProjectUrl(),
            'token' => $config->getSourceProjectToken(),
        ]);
        $sourceProjectClient->setRunId($this->getKbcRunId());

        $destinationProjectClient = new Client([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);
        $destinationProjectClient->setRunId($this->getKbcRunId());

        $writerMigrate = new GoodDataWriterMigrate(
            $destinationProjectClient,
            parse_url($destinationProjectClient->getApiUrl(), PHP_URL_HOST),
            parse_url($sourceProjectClient->getApiUrl(), PHP_URL_HOST)
        );

        $writersToMigrate = (new Components($sourceProjectClient))->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())
                ->setComponentId(GoodDataWriterMigrate::GOOD_DATA_WRITER_COMPONENT_ID)
        );

        foreach ($writersToMigrate as $writerConfigurationWithRows) {
            $writerMigrate->migrateWriter($writerConfigurationWithRows);
        }
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
