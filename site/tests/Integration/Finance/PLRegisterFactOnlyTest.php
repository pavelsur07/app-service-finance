<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Analytics\Application\Widget\RevenueWidgetBuilder;
use App\Analytics\Domain\Period;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Company\Entity\ProjectDirection;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Application\Service\PLRegisterUpdater;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class PLRegisterFactOnlyTest extends IntegrationTestCase
{
    private PLRegisterUpdater $updater;
    private RevenueWidgetBuilder $revenueWidgetBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->updater = self::getContainer()->get(PLRegisterUpdater::class);
        $this->revenueWidgetBuilder = self::getContainer()->get(RevenueWidgetBuilder::class);
    }

    public function testDraftActiveDeleteFlowAffectsRevenueOnlyForActiveDocuments(): void
    {
        $company = $this->createCompany();
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Main Project');

        $incomeRoot = new PLCategory(Uuid::uuid4()->toString(), $company);
        $incomeRoot->setName('Revenue');
        $incomeRoot->setType(PLCategoryType::SUBTOTAL);

        $incomeLeaf = new PLCategory(Uuid::uuid4()->toString(), $company);
        $incomeLeaf->setName('Sales');
        $incomeLeaf->setParent($incomeRoot);
        $incomeLeaf->setCode('REV_SALES');
        $incomeLeaf->setFlow(PLFlow::INCOME);

        $documentDate = new \DateTimeImmutable('2026-01-10');

        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setStatus(DocumentStatus::DRAFT);
        $document->setDate($documentDate);
        $document->setType(DocumentType::OTHER);
        $document->setProjectDirection($project);

        $operation = new DocumentOperation();
        $operation->setPlCategory($incomeLeaf);
        $operation->setProjectDirection($project);
        $operation->setAmount('150.00');
        $document->addOperation($operation);

        $this->em->persist($company->getUser());
        $this->em->persist($company);
        $this->em->persist($project);
        $this->em->persist($incomeRoot);
        $this->em->persist($incomeLeaf);
        $this->em->persist($document);
        $this->em->flush();

        $this->updater->updateForDocument($document);
        self::assertSame(0.0, $this->revenueSum($company, $documentDate));

        $document->setStatus(DocumentStatus::ACTIVE);
        $this->em->flush();
        $this->updater->recalcRange($company, $documentDate, $documentDate);

        self::assertSame(150.0, $this->revenueSum($company, $documentDate));

        $this->em->remove($document);
        $this->em->flush();
        $this->updater->recalcRange($company, $documentDate, $documentDate);

        self::assertSame(0.0, $this->revenueSum($company, $documentDate));
    }

    private function revenueSum(Company $company, \DateTimeImmutable $day): float
    {
        $payload = $this->revenueWidgetBuilder->build($company, new Period($day, $day));

        return $payload['widget']->toArray()['sum'];
    }

    private function createCompany(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('fact-only@example.com');
        $user->setPassword('password');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Fact only company');

        return $company;
    }
}
