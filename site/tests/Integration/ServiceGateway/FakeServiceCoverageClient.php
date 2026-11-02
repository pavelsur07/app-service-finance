<?php

declare(strict_types=1);

namespace App\Tests\Integration\ServiceGateway;

use App\ServiceGateway\Application\DTO\ServiceCoverageReport;
use App\ServiceGateway\Infrastructure\Client\ServiceCoverageClientInterface;
use App\ServiceGateway\Infrastructure\Client\ServiceCoverageV1Parser;
use App\Tests\Unit\ServiceGateway\Infrastructure\Client\ServiceCoverageV1ParserTest;

final class FakeServiceCoverageClient implements ServiceCoverageClientInterface
{
    public int $calls = 0;

    /**
     * @param array<string, mixed>|ServiceCoverageReport|\Throwable|null $response null — пример контракта v1
     */
    public function __construct(
        private array|ServiceCoverageReport|\Throwable|null $response = null,
        private bool $enabled = true,
        private string $service = 'ozon-fetcher',
    ) {
    }

    public function service(): string
    {
        return $this->service;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function fetchCoverage(): ServiceCoverageReport
    {
        ++$this->calls;
        if ($this->response instanceof \Throwable) {
            throw $this->response;
        }
        if ($this->response instanceof ServiceCoverageReport) {
            return $this->response;
        }

        return (new ServiceCoverageV1Parser())->parse(ServiceCoverageV1ParserTest::decoded($this->response ?? ['service' => $this->service] + ServiceCoverageV1ParserTest::fixture()));
    }
}
