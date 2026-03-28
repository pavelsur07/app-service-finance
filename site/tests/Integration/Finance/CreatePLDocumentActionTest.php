<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Enum\CounterpartyType;
use App\Company\Entity\Counterparty;
use App\Entity\Document;
use App\Entity\PLCategory;
use App\Entity\PLDailyTotal;
use App\Company\Entity\ProjectDirection;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\PLFlow;
use App\Finance\Application\Command\CreatePLDocumentCommand;
use App\Finance\Application\Command\CreatePLDocumentOperationCommand;
use App\Finance\Application\CreatePLDocumentAction;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class CreatePLDocumentActionTest extends IntegrationTestCase
{
    public function testCreatesDocumentWithOperationsAndUpdatesDailyTotals(): void
    {
        [$company, $category, $counterparty, $project] = $this->createBaseFixtures('501');

        $documentId = $this->action()(new CreatePLDocumentCommand(
            companyId: (string) $company->getId(),
            date: new \DateTimeImmutable('2026-07-15 10:00:00'),
            type: DocumentType::OTHER,
            status: DocumentStatus::ACTIVE,
            number: 'PL-501',
            description: 'Документ для теста',
            counterpartyId: (string) $counterparty->getId(),
            projectDirectionId: (string) $project->getId(),
            operations: [
                new CreatePLDocumentOperationCommand(
                    amount: '1500.00',
                    categoryId: (string) $category->getId(),
                    counterpartyId: (string) $counterparty->getId(),
                    projectDirectionId: (string) $project->getId(),
                    comment: 'Операция 1',
                ),
            ],
        ));

        /** @var Document|null $document */
        $document = $this->em()->getRepository(Document::class)->find($documentId);
        self::assertInstanceOf(Document::class, $document);
        self::assertSame('PL-501', $document->getNumber());
        self::assertCount(1, $document->getOperations());

        $dailyTotals = $this->em()->getRepository(PLDailyTotal::class)->findAll();
        self::assertCount(1, $dailyTotals);
        self::assertSame('0.00', $dailyTotals[0]->getAmountIncome());
        self::assertSame('1500.00', $dailyTotals[0]->getAmountExpense());
    }

    public function testThrowsWhenOperationsAreEmpty(): void
    {
        [$company] = $this->createBaseFixtures('502');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Документ ОПиУ должен содержать хотя бы одну операцию.');

        $this->action()(new CreatePLDocumentCommand(
            companyId: (string) $company->getId(),
            date: new \DateTimeImmutable('2026-07-16'),
            type: DocumentType::OTHER,
            status: DocumentStatus::ACTIVE,
            operations: [],
        ));
    }

    public function testThrowsWhenCategoryBelongsToAnotherCompany(): void
    {
        [$companyA] = $this->createBaseFixtures('503');
        [, $categoryB] = $this->createBaseFixtures('504');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Категория ОПиУ не найдена.');

        $this->action()(new CreatePLDocumentCommand(
            companyId: (string) $companyA->getId(),
            date: new \DateTimeImmutable('2026-07-17'),
            type: DocumentType::OTHER,
            status: DocumentStatus::ACTIVE,
            operations: [
                new CreatePLDocumentOperationCommand(amount: '100.00', categoryId: (string) $categoryB->getId()),
            ],
        ));
    }

    /**
     * @return array{0: Company, 1: PLCategory, 2: Counterparty, 3: ProjectDirection}
     */
    private function createBaseFixtures(string $suffix): array
    {
        $owner = UserBuilder::aUser()
            ->withId(sprintf('22222222-2222-2222-2222-%012d', (int) $suffix))
            ->withEmail(sprintf('owner-%s@example.test', $suffix))
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(sprintf('11111111-1111-1111-1111-%012d', (int) $suffix))
            ->withOwner($owner)
            ->withName(sprintf('Company %s', $suffix))
            ->build();

        $category = (new PLCategory(sprintf('33333333-3333-3333-3333-%012d', (int) $suffix), $company))
            ->setName(sprintf('Category %s', $suffix))
            ->setFlow(PLFlow::EXPENSE)
            ->setCode(sprintf('CAT_%s', $suffix));

        $counterparty = new Counterparty(
            sprintf('44444444-4444-4444-4444-%012d', (int) $suffix),
            $company,
            sprintf('Counterparty %s', $suffix),
            CounterpartyType::LEGAL_ENTITY,
        );

        $project = new ProjectDirection(
            sprintf('55555555-5555-5555-5555-%012d', (int) $suffix),
            $company,
            sprintf('Project %s', $suffix),
        );

        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->persist($category);
        $this->em()->persist($counterparty);
        $this->em()->persist($project);
        $this->em()->flush();

        return [$company, $category, $counterparty, $project];
    }

    private function action(): CreatePLDocumentAction
    {
        return self::getContainer()->get(CreatePLDocumentAction::class);
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        return $this->em;
    }
}
