<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Facade;

use App\ServiceGateway\Application\Action\ImportAiResultsAction;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\ServiceGateway\Facade\AiTaskResultFacade;
use App\ServiceGateway\Infrastructure\Client\AiServiceClient;
use App\ServiceGateway\Infrastructure\Client\AiTaskV1Parser;
use App\Tests\Unit\ServiceGateway\Infrastructure\Client\AiTaskV1ParserTest;
use Doctrine\Persistence\ManagerRegistry;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiTaskResultFacadeTest extends TestCase
{
    public function testForeignTaskIsRejectedBeforeConsumptionOrAck(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;
            $page = AiTaskV1ParserTest::fixture('results_page');
            $page->items = [$page->items[0]];

            return new MockResponse(json_encode($page, \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $logger = new Logger('test');
        $client = new AiServiceClient($http, new AiTaskV1Parser(), $logger, 'http://ai-service:8080', 'test-token');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManager');
        $importer = new ImportAiResultsAction($client, [], $registry, $logger, []);
        $access = $this->createMock(AiTaskFacadeInterface::class);
        $access->method('isEnabledForCompany')->willReturn(true);
        $facade = new AiTaskResultFacade($client, $importer, $access);

        try {
            $facade->refreshTask('0192f0a0-0000-7000-8000-000000000099', '0192f0a0-0000-7000-8000-000000000098');
            self::fail('foreign result must be rejected');
        } catch (ContractViolationException) {
            self::assertSame(1, $calls);
        }
    }
}
