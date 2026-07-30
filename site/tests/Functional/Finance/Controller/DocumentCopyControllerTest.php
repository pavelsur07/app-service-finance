<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance\Controller;

use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Enum\CounterpartyType;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DocumentCopyControllerTest extends WebTestCaseBase
{
    public function testCopyCreatesPrefilledDocument(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->resetDb();

        $user = $this->createUser($hasher, 'copy@example.com');
        $company = $this->createCompany($user, 'Copy Co');
        $counterparty = new Counterparty(Uuid::uuid4()->toString(), $company, (new CounterpartyNameNormalizer())->normalize('Client'), CounterpartyType::LEGAL_ENTITY);
        $systemProject = new ProjectDirection(
            Uuid::uuid4()->toString(),
            $company,
            ProjectDirection::CODE_GENERAL,
            ProjectDirection::CODE_GENERAL,
        );
        $systemCenter = new FinancialResponsibilityCenter(
            (string) $company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Продажа компьютеров');
        $center = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_KRD', 'Краснодар');

        $incomeCategory = new PLCategory(Uuid::uuid4()->toString(), $company);
        $incomeCategory->setName('Income');
        $incomeCategory->setFlow(PLFlow::INCOME);

        $expenseCategory = new PLCategory(Uuid::uuid4()->toString(), $company);
        $expenseCategory->setName('Expense');
        $expenseCategory->setFlow(PLFlow::EXPENSE);

        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setDate(new \DateTimeImmutable('2024-02-01'));
        $document->setNumber('DOC-1');
        $document->setType(DocumentType::OTHER);
        $document->setCounterparty($counterparty);
        $document->setDescription('Original document');
        $document->setProjectDirection($project);
        $document->setResponsibilityCenterId($center->getId());

        $incomeOperation = new DocumentOperation();
        $incomeOperation->setPlCategory($incomeCategory);
        $incomeOperation->setAmount('100.00');
        $incomeOperation->setCounterparty($counterparty);
        $incomeOperation->setProjectDirection($project);
        $incomeOperation->setResponsibilityCenterId($center->getId());
        $incomeOperation->setComment('Income line');
        $document->addOperation($incomeOperation);

        $expenseOperation = new DocumentOperation();
        $expenseOperation->setPlCategory($expenseCategory);
        $expenseOperation->setAmount('40.00');
        $expenseOperation->setCounterparty($counterparty);
        $expenseOperation->setProjectDirection($project);
        $expenseOperation->setResponsibilityCenterId($center->getId());
        $expenseOperation->setComment('Expense line');
        $document->addOperation($expenseOperation);

        $em->persist($user);
        $em->persist($company);
        $em->persist($counterparty);
        $em->persist($systemProject);
        $em->persist($systemCenter);
        $em->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $systemProject, $systemCenter));
        $em->persist($project);
        $em->persist($center);
        $em->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $project, $center));
        $em->persist($incomeCategory);
        $em->persist($expenseCategory);
        $em->persist($document);
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('GET', sprintf('/documents/%s/copy', $document->getId()));

        self::assertResponseIsSuccessful();

        $client->submitForm('Сохранить');

        self::assertResponseRedirects('/documents/');
        $client->followRedirect();

        $documents = $em->getRepository(Document::class)->findBy(['company' => $company]);
        self::assertCount(2, $documents);

        $copied = null;
        foreach ($documents as $doc) {
            if ($doc->getId() !== $document->getId()) {
                $copied = $doc;
                break;
            }
        }

        self::assertNotNull($copied);
        self::assertSame($document->getDescription(), $copied->getDescription());
        self::assertSame($document->getNumber(), $copied->getNumber());
        self::assertSame($document->getType(), $copied->getType());
        self::assertSame($document->getCounterparty()?->getId(), $copied->getCounterparty()?->getId());
        self::assertSame($document->getProjectDirection()?->getId(), $copied->getProjectDirection()?->getId());
        self::assertSame($document->getResponsibilityCenterId(), $copied->getResponsibilityCenterId());
        self::assertEquals($document->getDate(), $copied->getDate());

        $originalOperations = $document->getOperations();
        $copiedOperations = $copied->getOperations();

        self::assertCount($originalOperations->count(), $copiedOperations);

        $mappedCopied = [];
        foreach ($copiedOperations as $operation) {
            $mappedCopied[$operation->getComment() ?? ''] = [
                'amount' => $operation->getAmount(),
                'category' => $operation->getPlCategory()?->getName(),
                'counterparty' => $operation->getCounterparty()?->getId(),
                'project' => $operation->getProjectDirection()?->getId(),
                'responsibilityCenterId' => $operation->getResponsibilityCenterId(),
                'id' => $operation->getId(),
            ];
        }

        foreach ($originalOperations as $operation) {
            $key = $operation->getComment() ?? '';
            self::assertArrayHasKey($key, $mappedCopied);
            self::assertSame($operation->getAmount(), $mappedCopied[$key]['amount']);
            self::assertSame($operation->getPlCategory()?->getName(), $mappedCopied[$key]['category']);
            self::assertSame($operation->getCounterparty()?->getId(), $mappedCopied[$key]['counterparty']);
            self::assertSame($operation->getProjectDirection()?->getId(), $mappedCopied[$key]['project']);
            self::assertSame($operation->getResponsibilityCenterId(), $mappedCopied[$key]['responsibilityCenterId']);
            self::assertNotSame($operation->getId(), $mappedCopied[$key]['id']);
        }
    }

    private function createUser(UserPasswordHasherInterface $hasher, string $email): User
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        return $user;
    }

    private function createCompany(User $user, string $name): Company
    {
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName($name);

        return $company;
    }
}
