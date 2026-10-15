<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\CreateCashFileImportProfileAction;
use App\Cash\Application\DeleteCashFileImportProfileAction;
use App\Cash\Application\StartCashFileImportJobAction;
use App\Cash\Application\UpdateCashFileImportProfileAction;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Entity\Import\ImportLog;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Action'ы файлового импорта (этап 5.2 плана Cash; было CashFileImportFacade).
 */
final class CashFileImportActionsTest extends TestCase
{
    public function testCreateProfilePersistsAndFlushes(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(CashFileImportProfile::class));
        $entityManager->expects(self::once())->method('flush');

        $profile = (new CreateCashFileImportProfileAction($entityManager))(
            $this->company(),
            'Сбер',
            ['date' => 'A'],
            ['delimiter' => ';'],
            CashFileImportProfile::TYPE_CASH_TRANSACTION,
        );

        self::assertSame('Сбер', $profile->getName());
        self::assertSame(['date' => 'A'], $profile->getMapping());
    }

    public function testUpdateProfileChangesFieldsAndFlushesWithoutPersist(): void
    {
        $profile = new CashFileImportProfile(Uuid::uuid4()->toString(), $this->company(), 'Старое');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        (new UpdateCashFileImportProfileAction($entityManager))($profile, 'Новое', ['date' => 'B'], [], CashFileImportProfile::TYPE_CASH_TRANSACTION);

        self::assertSame('Новое', $profile->getName());
        self::assertSame(['date' => 'B'], $profile->getMapping());
    }

    public function testDeleteProfileRemovesAndFlushes(): void
    {
        $profile = new CashFileImportProfile(Uuid::uuid4()->toString(), $this->company(), 'Профиль');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($profile);
        $entityManager->expects(self::once())->method('flush');

        (new DeleteCashFileImportProfileAction($entityManager))($profile);
    }

    /**
     * Порядок записи прежний: журнал импорта сохраняется первым flush(),
     * задание со ссылкой на журнал — вторым.
     */
    public function testStartJobFlushesImportLogBeforeJob(): void
    {
        $company = $this->company();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $events = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$events): void {
            $events[] = 'persist:'.(new \ReflectionClass($entity))->getShortName();
        });
        $entityManager->method('flush')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'flush';
        });

        $job = (new StartCashFileImportJobAction($entityManager, new ImportLogger($entityManager)))(
            $company,
            $account,
            'cash:file',
            'statement.csv',
            'hash',
            ['date' => 'A'],
            ['stored_ext' => 'csv'],
            null,
        );

        self::assertSame(['persist:ImportLog', 'flush', 'persist:CashFileImportJob', 'flush'], $events);
        self::assertSame('statement.csv', $job->getFileName());
        self::assertInstanceOf(ImportLog::class, $job->getImportLog());
    }

    public function testStartJobDoesNotCreateJobWhenImportLogFlushFails(): void
    {
        $company = $this->company();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(ImportLog::class));
        $entityManager->method('flush')->willThrowException(new \RuntimeException('DB is down'));

        $this->expectException(\RuntimeException::class);

        (new StartCashFileImportJobAction($entityManager, new ImportLogger($entityManager)))(
            $company,
            MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build(),
            'cash:file',
            'statement.csv',
            'hash',
            [],
            [],
            null,
        );
    }

    private function company(): Company
    {
        return CompanyBuilder::aCompany()->withOwner(UserBuilder::aUser()->build())->build();
    }
}
