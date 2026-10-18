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
    ) {
    }

    public function service(): string
    {
        return 'ozon-fetcher';
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

        return (new ServiceCoverageV1Parser())->parse($this->response ?? ServiceCoverageV1ParserTest::fixture());
    }
}
