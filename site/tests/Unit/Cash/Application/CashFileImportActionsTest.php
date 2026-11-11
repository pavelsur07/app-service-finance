<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\CreateCashFileImportProfileAction;
use App\Cash\Application\DeleteCashFileImportProfileAction;
use App\Cash\Application\UpdateCashFileImportProfileAction;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Company\Entity\Company;
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

        $profile = (new CreateCashFileImportProfileAction($entityManager, new \App\Cash\Service\Import\File\CashFileMappingValidator()))(
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

        (new UpdateCashFileImportProfileAction($entityManager, new \App\Cash\Service\Import\File\CashFileMappingValidator()))($profile, 'Новое', ['date' => 'B'], [], CashFileImportProfile::TYPE_CASH_TRANSACTION);

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

    private function company(): Company
    {
        return CompanyBuilder::aCompany()->withOwner(UserBuilder::aUser()->build())->build();
    }
}
