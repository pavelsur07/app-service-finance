<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application;

use App\Marketplace\Application\RecordConnectionAuthResultAction;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Политика уровня Application. Сам переход состояния выполняется одним
 * атомарным оператором и проверяется на живой БД в
 * {@see \App\Tests\Integration\Marketplace\Facade\ConnectionAuthStateTest}.
 */
final class RecordConnectionAuthResultActionTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-4111-8111-111111111111';
    private const CONNECTION_ID = '22222222-2222-4222-8222-222222222222';

    public function testFailurePassesCompanyScopeAndThresholdToRepository(): void
    {
        $repository = $this->createMock(MarketplaceConnectionRepository::class);
        $repository->expects(self::once())
            ->method('registerAuthFailure')
            ->with(
                self::CONNECTION_ID,
                self::COMPANY_ID,
                RecordConnectionAuthResultAction::AUTH_FAILURE_THRESHOLD,
                self::isInstanceOf(\DateTimeImmutable::class),
            )
            ->willReturn(true);

        $action = new RecordConnectionAuthResultAction($repository, new NullLogger());

        self::assertTrue($action->recordFailure(self::COMPANY_ID, self::CONNECTION_ID));
    }

    public function testSuccessPassesCompanyScopeToRepository(): void
    {
        $repository = $this->createMock(MarketplaceConnectionRepository::class);
        $repository->expects(self::once())
            ->method('registerAuthSuccess')
            ->with(self::CONNECTION_ID, self::COMPANY_ID, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(false);

        $action = new RecordConnectionAuthResultAction($repository, new NullLogger());

        self::assertFalse($action->recordSuccess(self::COMPANY_ID, self::CONNECTION_ID));
    }

    /**
     * Сбой записи состояния не имеет права подменить исходную причину: вызов
     * идёт по пути, который уже обрабатывает ошибку API, и брошенное отсюда
     * исключение увело бы диагностику в БД вместо протухшего ключа.
     */
    public function testDatabaseFailureIsSwallowedAndLogged(): void
    {
        $repository = $this->createMock(MarketplaceConnectionRepository::class);
        $repository->method('registerAuthFailure')->willThrowException(new DbalException('connection lost'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $action = new RecordConnectionAuthResultAction($repository, $logger);

        self::assertFalse($action->recordFailure(self::COMPANY_ID, self::CONNECTION_ID));
    }

    public function testNonUuidArgumentsAreRejectedBeforeTouchingTheDatabase(): void
    {
        $repository = $this->createMock(MarketplaceConnectionRepository::class);
        $repository->expects(self::never())->method('registerAuthFailure');

        $action = new RecordConnectionAuthResultAction($repository, new NullLogger());

        $this->expectException(\InvalidArgumentException::class);

        $action->recordFailure('not-a-uuid', self::CONNECTION_ID);
    }
}
