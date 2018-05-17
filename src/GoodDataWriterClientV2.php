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
        $job = $this->client->send($request)->json();

        if (!isset($job['url'])) {
            throw new UserException(
                'Create writer job returned unexpected result: ' . json_encode($job, JSON_PRETTY_PRINT)
            );
        }

        return $this->client->waitForJob($job['url']);
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
}
