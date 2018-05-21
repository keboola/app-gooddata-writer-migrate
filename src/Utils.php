<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriterMigrate;

class Utils
{
    public static function getKeboolaServiceUrl(array $services, string $serviceId): string
    {
        $foundServices = array_values(array_filter($services, function ($service) use ($serviceId) {
            return $service['id'] === $serviceId;
        }));
        if (empty($foundServices)) {
            throw new \Exception('syrup service not found');
        }
        return $foundServices[0]['url'];
    }
}
