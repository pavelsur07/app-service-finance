<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance\Controller;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class DocumentSoftDeleteReadBoundaryControllerTest extends WebTestCaseBase
{
    public function testDeletedDocumentCannotBeOpenedEditedCopiedOrExported(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->createCompanyContext();
        $active = $this->createDocument($company, 'ACTIVE-DOCUMENT');
        $document = $this->createDocument($company, 'DELETED-DOCUMENT');
        $document->markDeleted((string) $user->getId(), 'manual-ui');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $user, $company);

        foreach ([
            sprintf('/documents/%s', $active->getId()),
            sprintf('/documents/%s/edit', $active->getId()),
            sprintf('/documents/%s/copy', $active->getId()),
            sprintf('/documents/%s/json', $active->getId()),
        ] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }

        foreach ([
            sprintf('/documents/%s', $document->getId()),
            sprintf('/documents/%s/edit', $document->getId()),
            sprintf('/documents/%s/copy', $document->getId()),
            sprintf('/documents/%s/json', $document->getId()),
        ] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testCashTransactionPageListsOnlyActivePnlDocuments(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->createCompanyContext();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->build();
        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '300.00',
            'RUB',
            new \DateTimeImmutable('2026-08-01'),
        );
        $active = $this->createDocument($company, 'ACTIVE-PNL-DOCUMENT', '100.00');
        $deleted = $this->createDocument($company, 'DELETED-PNL-DOCUMENT', '100.00');
        $deleted->markDeleted((string) $user->getId(), 'manual-ui');
        $transaction->addDocument($active);
        $transaction->addDocument($deleted);

        $em = $this->em();
        $em->persist($account);
        $em->persist($transaction);
        $em->flush();

        $this->loginWithActiveCompany($client, $user, $company);
        $client->request('GET', sprintf('/finance/cash-transactions/%s', $transaction->getId()));

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('ACTIVE-PNL-DOCUMENT', $content);
        self::assertStringNotContainsString('DELETED-PNL-DOCUMENT', $content);
    }

    /** @return array{0: User, 1: Company} */
    private function createCompanyContext(): array
    {
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(Uuid::uuid4()->toString().'@example.test')
            ->asCompanyOwner()
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($user)
            ->build();

        $this->em()->persist($user);
        $this->em()->persist($company);

        return [$user, $company];
    }

    private function createDocument(Company $company, string $number, string $amount = '100.00'): Document
    {
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setDate(new \DateTimeImmutable('2026-08-01'));
        $document->setNumber($number);

        $operation = new DocumentOperation();
        $operation->setAmount($amount);
        $document->addOperation($operation);

        $this->em()->persist($document);

        return $document;
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
