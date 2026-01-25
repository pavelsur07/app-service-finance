<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Import\File;

use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Import\File\CashFileImportService;
use App\Cash\Service\Import\File\CashFileRowNormalizer;
use App\Cash\Service\Import\ImportLogger;
use App\Repository\CounterpartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CashFileImportServiceTest extends TestCase
{
    public function testDedupeHashIsStableForSameInput(): void
    {
        $service = new CashFileImportService(
            $this->createMock(CashFileRowNormalizer::class),
            $this->createMock(CounterpartyRepository::class),
            $this->createMock(CashTransactionRepository::class),
            new ImportLogger($this->createMock(EntityManagerInterface::class)),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AccountBalanceService::class),
            '/tmp'
        );

        $method = new \ReflectionMethod(CashFileImportService::class, 'makeDedupeHash');
        $method->setAccessible(true);

        $occurredAt = new \DateTimeImmutable('2025-12-01', new \DateTimeZone('UTC'));

        $hashOne = $method->invoke(
            $service,
            'company-id',
            'account-id',
            $occurredAt,
            1000,
            'Назначение платежа'
        );
        $hashTwo = $method->invoke(
            $service,
            'company-id',
            'account-id',
            $occurredAt,
            1000,
            'Назначение платежа'
        );

        self::assertSame($hashOne, $hashTwo);
    }
}
