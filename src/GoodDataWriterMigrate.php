<?php

namespace Keboola\GoodDataWriterMigrate;

use Keboola\StorageApi\Client;

class GoodDataWriterMigrate
{
    /** @var Client  */
    private $destinationProjectSapiClient;

    public function __construct(
        Client $destinationProjectSapiClient
    )
    {
        $this->destinationProjectSapiClient = $destinationProjectSapiClient;
    }

    public function migrateWriter(array $sourceWriterConfiguration)
    {
        $this->createNewGoodDataWriterInDestinationProject($sourceWriterConfiguration['id']);
    }

    private function createNewGoodDataWriterInDestinationProject(string $writerId)
    {
        $goodDataWriterClient = \Keboola\Writer\GoodData\Client::factory([
            'url' => sprintf("%s/gooddata-writer", $this->getDestinationProjectSyrupUrl()),
            'token' => $this->destinationProjectSapiClient->getTokenString(),
        ]);
        $goodDataWriterClient->createWriter($writerId);
    }

    private function getDestinationProjectSyrupUrl()
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
