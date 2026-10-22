<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Пересчёт регистра ОПиУ — замена окна одной транзакцией без собственного flush(): не сохраняет
 * чужой UnitOfWork и во внешней транзакции вызывающего откатывается вместе с ней.
 */
final class PLRegisterUpdaterTransactionTest extends IntegrationTestCase
{
    private const DAY = '2026-01-10';

    private PLRegisterUpdater $updater;
    private Company $company;
    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->updater = self::getContainer()->get(PLRegisterUpdater::class);

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail(Uuid::uuid4()->toString().'@example.com');
        $user->setPassword('password');

        $this->company = new Company(Uuid::uuid4()->toString(), $user);
        $this->company->setName('Register transaction company');
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'Main');

        $root = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $root->setName('Revenue');
        $root->setType(PLCategoryType::SUBTOTAL);

        $leaf = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $leaf->setName('Sales');
        $leaf->setParent($root);
        $leaf->setCode('REV_SALES');
        $leaf->setFlow(PLFlow::INCOME);

        $this->document = new Document(Uuid::uuid4()->toString(), $this->company);
        $this->document->setStatus(DocumentStatus::ACTIVE);
        $this->document->setDate(new \DateTimeImmutable(self::DAY));
        $this->document->setType(DocumentType::OTHER);
        $this->document->setProjectDirection($project);
        $this->document->setDescription('original');

        $operation = new DocumentOperation();
        $operation->setPlCategory($leaf);
        $operation->setProjectDirection($project);
        $operation->setAmount('150.00');
        $this->document->addOperation($operation);

        foreach ([$user, $this->company, $project, $root, $leaf, $this->document] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testRecalcDoesNotFlushCallerUnitOfWork(): void
    {
        $this->document->setDescription('pending, not flushed');

        $this->recalc();

        self::assertSame('original', $this->connection->fetchOne(
            'SELECT description FROM documents WHERE id = :id',
            ['id' => $this->document->getId()],
        ));
        self::assertSame('150.00', $this->registerIncome());
    }

    public function testRecalcInsideCallerTransactionRollsBackWithIt(): void
    {
        $this->recalc();
        self::assertSame('150.00', $this->registerIncome());

        $this->connection->beginTransaction();
        $this->connection->executeStatement(
            "UPDATE document_operations SET amount = '999.00' WHERE document_id = :id",
            ['id' => $this->document->getId()],
        );
        $this->em->clear();
        $this->recalc();
        self::assertSame('999.00', $this->registerIncome());
        $this->connection->rollBack();

        self::assertSame('150.00', $this->registerIncome());
    }

    private function recalc(): void
    {
        $day = new \DateTimeImmutable(self::DAY);
        $company = $this->em->getReference(Company::class, (string) $this->company->getId())
            ?? throw new \LogicException('Company reference is missing.');

        $this->updater->recalcRange($company, $day, $day);
    }

    private function registerIncome(): ?string
    {
        $income = $this->connection->fetchOne(
            'SELECT SUM(amount_income) FROM pl_daily_totals WHERE company_id = :company',
            ['company' => $this->company->getId()],
        );

        return false === $income || null === $income ? null : (string) $income;
    }
}
