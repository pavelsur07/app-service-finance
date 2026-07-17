<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Application\ArchiveFinancialResponsibilityCenterAction;
use App\Company\Application\ConfigureFinancialResponsibilityCenterProjectsAction;
use App\Company\Application\CreateFinancialResponsibilityCenterAction;
use App\Company\Application\UpdateFinancialResponsibilityCenterAction;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Enum\FinancialResponsibilityCenterStatus;
use App\Company\Repository\FinancialResponsibilityCenterProjectRepository;
use App\Company\Repository\FinancialResponsibilityCenterRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class FinancialResponsibilityCenterActionsTest extends IntegrationTestCase
{
    public function testCreatesAndUpdatesCompanyCenter(): void
    {
        $company = $this->persistCompany(701);
        /** @var FinancialResponsibilityCenterRepository $repository */
        $repository = self::getContainer()->get(FinancialResponsibilityCenterRepository::class);
        $create = new CreateFinancialResponsibilityCenterAction($this->em);
        $update = new UpdateFinancialResponsibilityCenterAction($repository, $this->em);

        $id = $create((string) $company->getId(), '  Краснодар  ', 20);
        $center = $repository->findOneByIdAndCompanyId($id, (string) $company->getId());

        self::assertInstanceOf(FinancialResponsibilityCenter::class, $center);
        self::assertMatchesRegularExpression('/^CFO_[A-F0-9]{32}$/', $center->getCode());
        self::assertSame('Краснодар', $center->getName());
        self::assertSame(20, $center->getSort());
        self::assertSame(1, $center->getVersion());

        $update((string) $company->getId(), $id, 1, 'Ростов', 30);

        self::assertSame('Ростов', $center->getName());
        self::assertSame(30, $center->getSort());
        self::assertSame(2, $center->getVersion());
    }

    public function testUpdateRejectsStaleVersionAndCrossCompanyId(): void
    {
        $company = $this->persistCompany(702);
        $otherCompany = $this->persistCompany(703);
        $center = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_SOUTH', 'Юг');
        $this->em->persist($center);
        $this->em->flush();

        /** @var FinancialResponsibilityCenterRepository $repository */
        $repository = self::getContainer()->get(FinancialResponsibilityCenterRepository::class);
        $update = new UpdateFinancialResponsibilityCenterAction($repository, $this->em);
        $update((string) $company->getId(), $center->getId(), 1, 'Южный офис', 10);

        try {
            $update((string) $company->getId(), $center->getId(), 1, 'Устаревшая запись', 20);
            self::fail('A stale version must be rejected.');
        } catch (\DomainException $exception) {
            self::assertSame('ЦФО был изменён другим пользователем. Обновите страницу.', $exception->getMessage());
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ЦФО не найден.');
        $update((string) $otherCompany->getId(), $center->getId(), 2, 'Чужой ЦФО', 30);
    }

    public function testArchivesRegularCenterButProtectsSystemCenter(): void
    {
        $company = $this->persistCompany(704);
        $systemCenter = new FinancialResponsibilityCenter(
            (string) $company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );
        $regularCenter = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_NORTH', 'Север');
        $this->em->persist($systemCenter);
        $this->em->persist($regularCenter);
        $this->em->flush();

        /** @var FinancialResponsibilityCenterRepository $repository */
        $repository = self::getContainer()->get(FinancialResponsibilityCenterRepository::class);
        $archive = new ArchiveFinancialResponsibilityCenterAction($repository, $this->em);

        try {
            $archive((string) $company->getId(), $systemCenter->getId(), 1);
            self::fail('The system center must not be archived.');
        } catch (\DomainException $exception) {
            self::assertSame('Системный ЦФО нельзя архивировать.', $exception->getMessage());
        }

        $archive((string) $company->getId(), $regularCenter->getId(), 1);
        self::assertSame(FinancialResponsibilityCenterStatus::ARCHIVED, $regularCenter->getStatus());
        self::assertSame(2, $regularCenter->getVersion());
    }

    public function testConfiguresCompanyProjectsAndRejectsStaleVersion(): void
    {
        $company = $this->persistCompany(705);
        $projectA = new ProjectDirection('33333333-3333-3333-3333-000000000705', $company, 'Продажи');
        $projectB = new ProjectDirection('33333333-3333-3333-3333-000000000706', $company, 'Сервис');
        $center = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_KRASNODAR', 'Краснодар');
        foreach ([$projectA, $projectB, $center] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $configure = $this->configureAction();
        /** @var FinancialResponsibilityCenterProjectRepository $pairRepository */
        $pairRepository = self::getContainer()->get(FinancialResponsibilityCenterProjectRepository::class);

        $configure((string) $company->getId(), $center->getId(), 1, [
            (string) $projectB->getId(),
            (string) $projectA->getId(),
            (string) $projectA->getId(),
        ]);

        $projectIds = $pairRepository->findProjectIds((string) $company->getId(), $center->getId());
        \sort($projectIds);
        $expectedIds = [(string) $projectA->getId(), (string) $projectB->getId()];
        \sort($expectedIds);
        self::assertSame($expectedIds, $projectIds);
        self::assertSame(2, $center->getVersion());

        $configure((string) $company->getId(), $center->getId(), 2, [
            (string) $projectA->getId(),
            (string) $projectB->getId(),
        ]);
        self::assertSame(2, $center->getVersion());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ЦФО был изменён другим пользователем. Обновите страницу.');
        $configure((string) $company->getId(), $center->getId(), 1, [(string) $projectA->getId()]);
    }

    public function testProjectConfigurationRejectsCrossCompanyProject(): void
    {
        $company = $this->persistCompany(706);
        $otherCompany = $this->persistCompany(707);
        $otherProject = new ProjectDirection('33333333-3333-3333-3333-000000000707', $otherCompany, 'Чужой проект');
        $center = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_LOCAL', 'Локальный');
        $this->em->persist($otherProject);
        $this->em->persist($center);
        $this->em->flush();

        $configure = $this->configureAction();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Один или несколько проектов не найдены в активной компании.');
        $configure(
            (string) $company->getId(),
            $center->getId(),
            1,
            [(string) $otherProject->getId()],
        );
    }

    public function testSystemProjectPairCannotBeRemoved(): void
    {
        $company = $this->persistCompany(708);
        $project = new ProjectDirection(
            '33333333-3333-3333-3333-000000000708',
            $company,
            'Общий',
            ProjectDirection::CODE_GENERAL,
        );
        $center = new FinancialResponsibilityCenter(
            (string) $company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );
        $pair = new FinancialResponsibilityCenterProject((string) $company->getId(), $project, $center);
        foreach ([$project, $center, $pair] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $configure = $this->configureAction();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Системную пару проекта и ЦФО нельзя удалить.');
        $configure((string) $company->getId(), $center->getId(), 1, []);
    }

    private function persistCompany(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()
            ->withIndex($index)
            ->withOwner($owner)
            ->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function configureAction(): ConfigureFinancialResponsibilityCenterProjectsAction
    {
        /** @var FinancialResponsibilityCenterRepository $centerRepository */
        $centerRepository = self::getContainer()->get(FinancialResponsibilityCenterRepository::class);
        /** @var FinancialResponsibilityCenterProjectRepository $pairRepository */
        $pairRepository = self::getContainer()->get(FinancialResponsibilityCenterProjectRepository::class);
        /** @var ProjectDirectionRepository $projectRepository */
        $projectRepository = self::getContainer()->get(ProjectDirectionRepository::class);

        return new ConfigureFinancialResponsibilityCenterProjectsAction(
            $centerRepository,
            $pairRepository,
            $projectRepository,
            $this->em,
        );
    }
}
