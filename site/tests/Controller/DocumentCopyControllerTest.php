<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Enum\CounterpartyType;
use App\Entity\Counterparty;
use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
use App\Enum\DocumentType;
use App\Enum\PLFlow;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DocumentCopyControllerTest extends WebTestCase
{
    public function testCopyCreatesPrefilledDocument(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->resetDatabase($em);

        $user = $this->createUser($hasher, 'copy@example.com');
        $company = $this->createCompany($user, 'Copy Co');
        $counterparty = new Counterparty(Uuid::uuid4()->toString(), $company, 'Client', CounterpartyType::CUSTOMER);

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

        $incomeOperation = new DocumentOperation();
        $incomeOperation->setPlCategory($incomeCategory);
        $incomeOperation->setAmount('100.00');
        $incomeOperation->setCounterparty($counterparty);
        $incomeOperation->setComment('Income line');
        $document->addOperation($incomeOperation);

        $expenseOperation = new DocumentOperation();
        $expenseOperation->setPlCategory($expenseCategory);
        $expenseOperation->setAmount('40.00');
        $expenseOperation->setCounterparty($counterparty);
        $expenseOperation->setComment('Expense line');
        $document->addOperation($expenseOperation);

        $em->persist($user);
        $em->persist($company);
        $em->persist($counterparty);
        $em->persist($incomeCategory);
        $em->persist($expenseCategory);
        $em->persist($document);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', sprintf('/documents/%s/copy', $document->getId()));

        self::assertResponseIsSuccessful();

        $client->submitForm('Сохранить');

        self::assertResponseRedirects('/documents');
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
                'id' => $operation->getId(),
            ];
        }

        foreach ($originalOperations as $operation) {
            $key = $operation->getComment() ?? '';
            self::assertArrayHasKey($key, $mappedCopied);
            self::assertSame($operation->getAmount(), $mappedCopied[$key]['amount']);
            self::assertSame($operation->getPlCategory()?->getName(), $mappedCopied[$key]['category']);
            self::assertSame($operation->getCounterparty()?->getId(), $mappedCopied[$key]['counterparty']);
            self::assertNotSame($operation->getId(), $mappedCopied[$key]['id']);
        }
    }

    private function resetDatabase(EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM App\\Entity\\DocumentOperation o')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\Document d')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\PLDailyTotal t')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\PLCategory c')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\Counterparty c')->execute();
        $em->createQuery('DELETE FROM App\\Company\\Entity\\Company c')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\User u')->execute();
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
