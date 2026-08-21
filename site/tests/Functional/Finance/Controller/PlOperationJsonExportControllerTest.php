<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance\Controller;

use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Enum\CounterpartyType;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PlOperationJsonExportControllerTest extends WebTestCaseBase
{
    private const EXPORT_URL = '/documents/operations/export.json';

    public function testGuestIsRedirectedOrForbidden(): void
    {
        $client = static::createClient();

        $client->request('GET', self::EXPORT_URL);

        $statusCode = $client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [302, 403]);
    }

    public function testReturnsJsonAttachmentWithOperationRows(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a1');
        $category = $this->seedCategory($company, 'Выручка', 'REVENUE');
        $document = $this->seedDocument($company, '2026-04-15', 'ДОК-1');
        $this->addOperation($document, $category, '15000.00');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', self::EXPORT_URL);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression(
            '/^attachment; filename="pl-operations-\d{8}-\d{6}\.json"$/',
            (string) $response->headers->get('Content-Disposition'),
        );

        $payload = $this->decodeJson($client);
        self::assertSame(1, $payload['count']);
        self::assertCount(1, $payload['operations']);

        $operation = $payload['operations'][0];
        self::assertSame('2026-04-15', $operation['date']);
        self::assertSame('ДОК-1', $operation['number']);
        self::assertSame('15000.00', $operation['amount']);
        // name и code различаются намеренно: перестановка колонок должна ронять тест
        self::assertSame('Выручка', $operation['category']);
        self::assertSame('REVENUE', $operation['category_code']);
        self::assertSame(PLFlow::INCOME->value, $operation['flow']);
        self::assertSame(DocumentStatus::ACTIVE->value, $operation['status']);
        self::assertSame($document->getId(), $operation['document_id']);
    }

    public function testEachOperationOfDocumentBecomesOwnRow(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a1');
        $income = $this->seedCategory($company, 'Выручка', 'REVENUE');
        $expense = $this->seedCategory($company, 'Реклама', 'ADS', PLFlow::EXPENSE);

        $document = $this->seedDocument($company, '2026-04-15', 'ДОК-1');
        $first = $this->addOperation($document, $income, '15000.00');
        $second = $this->addOperation($document, $expense, '2500.00');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', self::EXPORT_URL);

        $payload = $this->decodeJson($client);

        self::assertSame(2, $payload['count']);
        self::assertSame(
            [$document->getId(), $document->getId()],
            array_column($payload['operations'], 'document_id'),
        );

        $byId = array_column($payload['operations'], null, 'operation_id');
        self::assertArrayHasKey((string) $first->getId(), $byId);
        self::assertArrayHasKey((string) $second->getId(), $byId);
        self::assertSame('15000.00', $byId[(string) $first->getId()]['amount']);
        self::assertSame('REVENUE', $byId[(string) $first->getId()]['category_code']);
        self::assertSame('2500.00', $byId[(string) $second->getId()]['amount']);
        self::assertSame('ADS', $byId[(string) $second->getId()]['category_code']);
        self::assertSame(PLFlow::EXPENSE->value, $byId[(string) $second->getId()]['flow']);
    }

    public function testCounterpartyAndProjectFallBackToDocument(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a1');
        $category = $this->seedCategory($company, 'Выручка', 'REVENUE');

        $documentCounterparty = $this->seedCounterparty($company, 'ООО Документ');
        $operationCounterparty = $this->seedCounterparty($company, 'ООО Операция');
        $documentProject = $this->seedProject($company, 'Проект документа');
        $operationProject = $this->seedProject($company, 'Проект операции');

        $document = $this->seedDocument($company, '2026-04-15', 'ДОК-1');
        $document->setCounterparty($documentCounterparty);
        $document->setProjectDirection($documentProject);

        $inherited = $this->addOperation($document, $category, '100.00');
        $overridden = $this->addOperation($document, $category, '200.00');
        $overridden->setCounterparty($operationCounterparty);
        $overridden->setProjectDirection($operationProject);
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', self::EXPORT_URL);

        $payload = $this->decodeJson($client);
        $byId = array_column($payload['operations'], null, 'operation_id');

        self::assertSame('ООО Документ', $byId[(string) $inherited->getId()]['counterparty']);
        self::assertSame('Проект документа', $byId[(string) $inherited->getId()]['project']);
        self::assertSame('ООО Операция', $byId[(string) $overridden->getId()]['counterparty']);
        self::assertSame('Проект операции', $byId[(string) $overridden->getId()]['project']);
    }

    public function testDoesNotLeakForeignCompanyReferenceNames(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$userA, $companyA] = $this->seedCompanyContext('a1');
        [, $companyB] = $this->seedCompanyContext('b2');

        // испорченная строка: документ компании A ссылается на справочники компании B
        $foreignCategory = $this->seedCategory($companyB, 'Чужая категория', 'FOREIGN');
        $foreignCounterparty = $this->seedCounterparty($companyB, 'ООО Чужой');
        $foreignProject = $this->seedProject($companyB, 'Чужой проект');

        $document = $this->seedDocument($companyA, '2026-04-15', 'ДОК-1');
        $document->setCounterparty($foreignCounterparty);
        $document->setProjectDirection($foreignProject);
        $this->addOperation($document, $foreignCategory, '100.00');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $userA, $companyA);

        $client->request('GET', self::EXPORT_URL);

        $payload = $this->decodeJson($client);
        self::assertSame(1, $payload['count']);

        $operation = $payload['operations'][0];
        self::assertNull($operation['category']);
        self::assertNull($operation['category_code']);
        self::assertNull($operation['counterparty']);
        self::assertNull($operation['project']);
    }

    public function testExcludesOtherCompanyOperations(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$userA, $companyA] = $this->seedCompanyContext('a1');
        [, $companyB] = $this->seedCompanyContext('b2');

        $categoryA = $this->seedCategory($companyA, 'Выручка A', 'REVENUE_A');
        $categoryB = $this->seedCategory($companyB, 'Выручка B', 'REVENUE_B');

        $this->addOperation($this->seedDocument($companyA, '2026-04-01', 'A-1'), $categoryA, '100.00');
        $this->addOperation($this->seedDocument($companyA, '2026-04-02', 'A-2'), $categoryA, '200.00');
        $foreign = $this->addOperation($this->seedDocument($companyB, '2026-04-03', 'B-1'), $categoryB, '300.00');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $userA, $companyA);

        $client->request('GET', self::EXPORT_URL);

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        self::assertSame(2, $payload['count']);
        self::assertNotContains($foreign->getId(), array_column($payload['operations'], 'operation_id'));
        self::assertSame(['A-2', 'A-1'], array_column($payload['operations'], 'number'));
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function seedCompanyContext(string $suffix): array
    {
        $user = UserBuilder::aUser()
            ->withId(sprintf('22222222-2222-2222-2222-%s', str_pad($suffix, 12, '0', \STR_PAD_LEFT)))
            ->withEmail(sprintf('pl-operations-export-%s@example.test', $suffix))
            ->asCompanyOwner()
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(sprintf('11111111-1111-1111-1111-%s', str_pad($suffix, 12, '0', \STR_PAD_LEFT)))
            ->withName(sprintf('PL Operations Export Company %s', $suffix))
            ->withOwner($user)
            ->build();

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);

        return [$user, $company];
    }

    private function seedCategory(Company $company, string $name, string $code, PLFlow $flow = PLFlow::INCOME): PLCategory
    {
        $category = new PLCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        $category->setCode($code);
        $category->setFlow($flow);

        $this->em()->persist($category);

        return $category;
    }

    private function seedCounterparty(Company $company, string $name): Counterparty
    {
        $counterparty = new Counterparty(
            Uuid::uuid4()->toString(),
            $company,
            (new CounterpartyNameNormalizer())->normalize($name),
            CounterpartyType::LEGAL_ENTITY,
        );

        $this->em()->persist($counterparty);

        return $counterparty;
    }

    private function seedProject(Company $company, string $name): ProjectDirection
    {
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, $name);

        $this->em()->persist($project);

        return $project;
    }

    private function seedDocument(Company $company, string $date, string $number): Document
    {
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setType(DocumentType::OTHER);
        $document->setStatus(DocumentStatus::ACTIVE);
        $document->setDate(new \DateTimeImmutable($date));
        $document->setNumber($number);

        $this->em()->persist($document);

        return $document;
    }

    private function addOperation(Document $document, PLCategory $category, string $amount): DocumentOperation
    {
        $operation = new DocumentOperation();
        $operation->setPlCategory($category);
        $operation->setAmount($amount);
        $document->addOperation($operation);

        return $operation;
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    /** @return array<string, mixed> */
    private function decodeJson(KernelBrowser $client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
