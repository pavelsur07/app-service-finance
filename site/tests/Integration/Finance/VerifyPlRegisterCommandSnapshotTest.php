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
use App\Finance\Enum\PLFlow;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Без DAMA-транзакции: команда сама открывает снимок месяца (REPEATABLE READ), откатывает его
 * и возвращает соединению прежний уровень изоляции.
 */
final class VerifyPlRegisterCommandSnapshotTest extends PostgresResetTestCase
{
    public function testMonthIsReadInOwnReadOnlySnapshot(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail(Uuid::uuid4()->toString().'@example.com');
        $user->setPassword('password');
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Snapshot company');
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Main');

        $leaf = new PLCategory(Uuid::uuid4()->toString(), $company);
        $leaf->setName('Sales');
        $leaf->setCode('REV_SALES');
        $leaf->setFlow(PLFlow::INCOME);

        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setStatus(DocumentStatus::ACTIVE);
        $document->setDate(new \DateTimeImmutable('2026-01-10'));
        $document->setType(DocumentType::OTHER);
        $document->setProjectDirection($project);
        $operation = new DocumentOperation();
        $operation->setPlCategory($leaf);
        $operation->setAmount('150.00');
        $document->addOperation($operation);

        foreach ([$user, $company, $project, $leaf, $document] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $day = new \DateTimeImmutable('2026-01-10');
        self::getContainer()->get(PLRegisterUpdater::class)->recalcRange($company, $day, $day);
        $this->em->clear();

        self::assertFalse($this->connection->isTransactionActive());

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException('Kernel is not booted.')))
            ->find('app:finance:verify-pl-register'));

        $isolation = $this->connection->getTransactionIsolation();

        self::assertSame(Command::SUCCESS, $tester->execute(['--company' => (string) $company->getId()]), $tester->getDisplay());
        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame($isolation, $this->connection->getTransactionIsolation());
    }
}
