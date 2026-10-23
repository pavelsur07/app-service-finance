<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DocumentResponsibilityCenterControllerTest extends WebTestCaseBase
{
    public function testNewDocumentDefaultsEmptyProjectAndResponsibilityCenterToSystemPair(): void
    {
        [$client, $em, $graph] = $this->bootClientWithGraph('default');

        $this->submitDocumentForm($client, '/documents/new', $graph);

        self::assertResponseRedirects('/documents/resume');

        /** @var Document $document */
        $document = $em->getRepository(Document::class)->findOneBy(['number' => 'DOC-default']);
        self::assertSame($graph['systemProject']->getId(), $document->getProjectDirection()?->getId());
        self::assertSame($graph['systemCenter']->getId(), $document->getResponsibilityCenterId());

        $operation = $document->getOperations()->first();
        self::assertNotFalse($operation);
        self::assertSame($graph['systemProject']->getId(), $operation->getProjectDirection()?->getId());
        self::assertSame($graph['systemCenter']->getId(), $operation->getResponsibilityCenterId());
    }

    public function testNewDocumentSubmittedTwiceCreatesOneDocument(): void
    {
        [$client, , $graph] = $this->bootClientWithGraph('twice');

        $this->submitDocumentFormTwice($client, '/documents/new', $graph);
    }

    public function testNonCanonicalKeyIsNormalized(): void
    {
        [$client, , $graph] = $this->bootClientWithGraph('urn-key');

        $this->submitDocumentFormTwice($client, '/documents/new', $graph, 0, static fn (string $key): string => 'urn:uuid:'.strtoupper($key));
    }

    public function testCopySubmittedTwiceCreatesOneCopy(): void
    {
        [$client, $em, $graph] = $this->bootClientWithGraph('copy-twice');
        $source = new Document(Uuid::uuid7()->toString(), $graph['company']);
        $source->setDate(new \DateTimeImmutable('2026-07-18'))->setNumber('SRC-copy-twice')->setType(DocumentType::OTHER);
        $operation = new DocumentOperation();
        $operation->setCategory($graph['category'])->setAmount('100.00');
        $source->addOperation($operation);
        $em->persist($source);
        $em->flush();

        $this->submitDocumentFormTwice($client, sprintf('/documents/%s/copy', $source->getId()), $graph, 1);
    }

    public function testNewDocumentRejectsCustomProjectWithoutResponsibilityCenter(): void
    {
        [$client, $em, $graph] = $this->bootClientWithGraph('partial');

        $this->submitDocumentForm(
            $client,
            '/documents/new',
            $graph,
            $graph['customProject'],
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Укажите ЦФО для проекта.', $client->getResponse()->getContent() ?: '');
        self::assertNull($em->getRepository(Document::class)->findOneBy(['number' => 'DOC-partial']));
    }

    public function testEditPreservesArchivedCurrentResponsibilityCenter(): void
    {
        [$client, $em, $graph] = $this->bootClientWithGraph('archived');
        $document = new Document(Uuid::uuid4()->toString(), $graph['company']);
        $document
            ->setDate(new \DateTimeImmutable('2026-07-18'))
            ->setNumber('DOC-archived')
            ->setType(DocumentType::OTHER)
            ->setProjectDirection($graph['customProject'])
            ->setResponsibilityCenterId($graph['customCenter']->getId());
        $operation = new DocumentOperation();
        $operation
            ->setCategory($graph['category'])
            ->setAmount('100.00')
            ->setProjectDirection($graph['customProject'])
            ->setResponsibilityCenterId($graph['customCenter']->getId());
        $document->addOperation($operation);
        $graph['customCenter']->archive();

        $em->persist($document);
        $em->flush();

        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Краснодар [CFO_KRD]', $client->getResponse()->getContent() ?: '');

        $token = $crawler->filter('input[name="document[_token]"]')->attr('value');
        $client->request('POST', sprintf('/documents/%s/edit', $document->getId()), [
            'document' => $this->documentPayload(
                $token,
                $graph,
                'DOC-archived-updated',
                $graph['customProject'],
                $graph['customCenter']->getId(),
                $graph['customProject'],
                $graph['customCenter']->getId(),
            ),
        ]);

        self::assertResponseRedirects('/documents/resume');

        $em->clear();
        /** @var Document $reloaded */
        $reloaded = $em->getRepository(Document::class)->find($document->getId());
        self::assertSame('DOC-archived-updated', $reloaded->getNumber());
        self::assertSame($graph['customProject']->getId(), $reloaded->getProjectDirection()?->getId());
        self::assertSame($graph['customCenter']->getId(), $reloaded->getResponsibilityCenterId());
    }

    /**
     * @return array{0: KernelBrowser, 1: EntityManagerInterface, 2: array<string, mixed>}
     */
    private function bootClientWithGraph(string $suffix): array
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail(sprintf('document-%s@example.test', $suffix));
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName(sprintf('Company %s', $suffix));

        $category = (new PLCategory(Uuid::uuid4()->toString(), $company))
            ->setName('Выбытия')
            ->setFlow(PLFlow::EXPENSE);
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
        $customProject = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Продажа компьютеров');
        $customCenter = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_KRD', 'Краснодар');

        foreach ([$user, $company, $category, $systemProject, $systemCenter, $customProject, $customCenter] as $entity) {
            $em->persist($entity);
        }
        $em->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $systemProject, $systemCenter));
        $em->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $customProject, $customCenter));
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return [$client, $em, [
            'company' => $company,
            'category' => $category,
            'systemProject' => $systemProject,
            'systemCenter' => $systemCenter,
            'customProject' => $customProject,
            'customCenter' => $customCenter,
            'suffix' => $suffix,
        ]];
    }

    /**
     * Двойная отправка формы создания: ключ повтора из показанной формы становится ID документа,
     * вторая отправка того же ключа документ не создаёт.
     *
     * @param array<string, mixed> $graph
     * @param int $existing документов компании до отправки
     * @param (\Closure(string): string)|null $submitted как ключ записан в отправке (клиент мог изменить запись UUID)
     */
    private function submitDocumentFormTwice(KernelBrowser $client, string $path, array $graph, int $existing = 0, ?\Closure $submitted = null): void
    {
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="document[_token]"]')->attr('value');
        $key = $crawler->filter('input[name="document[idempotencyKey]"]')->attr('value');
        self::assertTrue(Uuid::isValid((string) $key));

        $payload = ['document' => $this->documentPayload($token, $graph, sprintf('DOC-%s', $graph['suffix']), null, null, null, null)
            + ['idempotencyKey' => null !== $submitted ? $submitted((string) $key) : $key]];
        $client->request('POST', $path, $payload);
        self::assertResponseRedirects('/documents/resume');
        $client->request('POST', $path, $payload);
        self::assertResponseRedirects('/documents/resume');

        $ids = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT id FROM documents WHERE company_id = :company AND number = :number',
            ['company' => $graph['company']->getId(), 'number' => sprintf('DOC-%s', $graph['suffix'])],
        );
        self::assertSame([$key], $ids);
        self::assertSame($existing + 1, (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE company_id = :company',
            ['company' => $graph['company']->getId()],
        ));
    }

    private function submitDocumentForm(
        KernelBrowser $client,
        string $path,
        array $graph,
        ?ProjectDirection $documentProject = null,
        ?string $documentResponsibilityCenterId = null,
        ?ProjectDirection $operationProject = null,
        ?string $operationResponsibilityCenterId = null,
    ): void {
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="document[_token]"]')->attr('value');
        $client->request('POST', $path, [
            'document' => $this->documentPayload(
                $token,
                $graph,
                sprintf('DOC-%s', $graph['suffix']),
                $documentProject,
                $documentResponsibilityCenterId,
                $operationProject,
                $operationResponsibilityCenterId,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentPayload(
        string $token,
        array $graph,
        string $number,
        ?ProjectDirection $documentProject,
        ?string $documentResponsibilityCenterId,
        ?ProjectDirection $operationProject,
        ?string $operationResponsibilityCenterId,
    ): array {
        return [
            'date' => '2026-07-18',
            'number' => $number,
            'type' => DocumentType::OTHER->value,
            'counterparty' => '',
            'projectDirection' => $documentProject?->getId() ?? '',
            'responsibilityCenterId' => $documentResponsibilityCenterId ?? '',
            'description' => '',
            'operations' => [
                [
                    'category' => $graph['category']->getId(),
                    'amount' => '100.00',
                    'counterparty' => '',
                    'projectDirection' => $operationProject?->getId() ?? '',
                    'responsibilityCenterId' => $operationResponsibilityCenterId ?? '',
                ],
            ],
            '_token' => $token,
        ];
    }
}
