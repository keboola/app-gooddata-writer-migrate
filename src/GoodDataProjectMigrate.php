<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriterMigrate;

use Keboola\Component\UserException;
use Keboola\GoodData\Client;

class GoodDataProjectMigrate
{

    public function migrate(
        Client $sourceProjectClient,
        string $sourceProjectPid,
        Client $destinationProjectClient,
        string $destinationProjectPid
    ) {
        $exportUri = "/gdc/md/$sourceProjectPid/maintenance/export";
        $params = [
            'exportProject' => [
                'exportUsers' => 0,
                'exportData' => 1,
                'crossDataCenterExport' => 1,
            ],
        ];
        $exportResult = $sourceProjectClient->post($exportUri, $params);
        if (empty($exportResult['exportArtifact']['token']) || empty($exportResult['exportArtifact']['status']['uri'])) {
            throw new UserException(
                sprintf('Project export failed: %s', json_encode($exportResult))
            );
        }
        $sourceProjectClient->pollTask($exportResult['exportArtifact']['status']['uri']);

        $importUri = "/gdc/md/$destinationProjectPid/maintenance/import";
        $importResult = $destinationProjectClient->post($importUri, [
            'importProject' => [
                'token' => $exportResult['exportArtifact']['token'],
            ],
        ]);
        if (empty($importResult['uri'])) {
            throw new UserException(sprintf('Project import failed: %s', json_decode($importResult)));
        }
        $sourceProjectClient->pollTask($importResult['uri']);
    }
}
