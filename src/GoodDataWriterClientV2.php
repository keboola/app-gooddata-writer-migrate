<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriterMigrate;

use Keboola\Component\UserException;
use Keboola\Writer\GoodData\Client as GoodDataWriterClient;

class GoodDataWriterClientV2
{
    /** @var GoodDataWriterClient  */
    private $client;

    public function __construct(GoodDataWriterClient $client)
    {
        $this->client = $client;
    }

    public static function factory(array $config): self
    {
        return new self(GoodDataWriterClient::factory($config));
    }

    public function createWriter(string $writerId, ?array $params = []): array
    {
        $extendedParams = array_merge($params, [
            'config' => $writerId,
        ]);
        $request = $this->client->post(
            'v2',
            null,
            json_encode($extendedParams)
        );
        return $this->waitForJob($request);
    }

    public function getWriter(string $writerId): array
    {
        $request = $this->client->get(sprintf('v2/%s?include=project,user', $writerId));
        return $this->client->send($request)->json();
    }

    public function getWriters(): array
    {
        $request = $this->client->get('v2');
        return $this->client->send($request)->json();
    }

    public function deleteWriter(string $id): void
    {
        $request = $this->client->delete(sprintf('v2/%s', $id));
        $this->client->send($request);
    }

    public function addTableToWriter(string $writer, string $tableId): void
    {
        $request = $this->client->post(
            sprintf('v2/%s/tables', $writer),
            null,
            json_encode([
                'tableId' => $tableId,
            ])
        );
        $this->client->send($request);
    }

    public function updateWriterTableConfiguration(string $writerId, string $tableId, array $tableConfig): void
    {
        $request = $this->client->patch(
            sprintf('v2/%s/tables/%s', $writerId, $tableId),
            null,
            json_encode($tableConfig)
        );
        $this->client->send($request);
    }

    public function updateWriterModel(string $writerId, string $pid): void
    {
        $request = $this->client->post(
            sprintf('v2/%s/projects/%s/update', $writerId, $pid)
        );
        $this->waitForJob($request);
    }

    public function loadWriterDataMulti(string $writerId, string $pid): void
    {
        $request = $this->client->post(
            sprintf('v2/%s/projects/%s/load-multi', $writerId, $pid)
        );
        $this->waitForJob($request);
    }

    public function listWriterTables(string $writerId): array
    {
        $request = $this->client->get(
            sprintf('v2/%s/tables?include=columns', $writerId)
        );
        return $this->client->send($request)->json();
    }

    private function waitForJob(\Guzzle\Http\Message\RequestInterface $request): array
    {
        $job = $this->client->send($request)->json();

        if (!isset($job['url'])) {
            throw new UserException(
                'Unexpected result of job enqueue: ' . json_encode($job, JSON_PRETTY_PRINT)
            );
        }

        return $this->client->waitForJob($job['url']);
    }
}
