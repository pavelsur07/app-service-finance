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
use App\Finance\Entity\PLDailyTotal;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RawPlReportControllerTest extends WebTestCaseBase
{
    public function testResponsibilityCenterFilterAppliesToOperationsAndDailyTotals(): void
    {
        [$client, $em, $graph] = $this->bootClientWithGraph();

        $mixedDocument = $this->createDocument($em, $graph, 'DOC-KRD', $graph['krasnodarProject'], null, $graph['krasnodarCenter']->getId());
        $mixedDocument->addOperation(
            (new DocumentOperation())
                ->setCategory($graph['category'])
                ->setAmount('300.00')
                ->setProjectDirection($graph['rostovProject'])
                ->setResponsibilityCenterId($graph['rostovCenter']->getId())
                ->setComment('mixed-rostov-op'),
        );
        $this->createDocument($em, $graph, 'DOC-RND', $graph['rostovProject'], $graph['rostovCenter']->getId(), null);
        $this->createDailyTotal($em, $graph, $graph['krasnodarProject'], $graph['krasnodarCenter']->getId(), '100.00');
        $this->createDailyTotal($em, $graph, $graph['rostovProject'], $graph['rostovCenter']->getId(), '200.00');
        $em->flush();

        $client->request('GET', sprintf(
            '/finance/reports/pl-raw?from=2026-07-01&to=2026-07-31&responsibilityCenterId=%s',
            $graph['krasnodarCenter']->getId(),
        ));

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent() ?: '';
        self::assertStringContainsString('№DOC-KRD', $content);
        self::assertStringContainsString('Краснодар', $content);
        self::assertStringContainsString('100.00', $content);
        self::assertStringNotContainsString('№DOC-RND', $content);
        self::assertStringNotContainsString('200.00', $content);
        self::assertStringNotContainsString('300.00', $content);
        self::assertStringNotContainsString('mixed-rostov-op', $content);

        $client->request('GET', '/finance/reports/pl-raw?from=2026-07-01&to=2026-07-31&responsibilityCenterId=not-a-uuid');

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent() ?: '';
        self::assertStringContainsString('№DOC-KRD', $content);
        self::assertStringContainsString('№DOC-RND', $content);
        self::assertStringContainsString('100.00', $content);
        self::assertStringContainsString('200.00', $content);
        self::assertStringContainsString('300.00', $content);
        self::assertStringContainsString('mixed-rostov-op', $content);
    }

    public function testSoftDeletedDocumentsAreExcluded(): void
    {
        [$client, $em, $graph] = $this->bootClientWithGraph();

        $this->createDocument($em, $graph, 'DOC-ACTIVE', $graph['krasnodarProject'], null, null);
        $deleted = $this->createDocument($em, $graph, 'DOC-DELETED', $graph['krasnodarProject'], null, null);
        $deleted->markDeleted('test-user', 'manual-ui');
        $em->flush();

        $client->request('GET', '/finance/reports/pl-raw?from=2026-07-01&to=2026-07-31');

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent() ?: '';
        self::assertStringContainsString('№DOC-ACTIVE', $content);
        self::assertStringNotContainsString('№DOC-DELETED', $content);
    }

    /**
     * @return array{0: KernelBrowser, 1: EntityManagerInterface, 2: array<string, mixed>}
     */
    private function bootClientWithGraph(): array
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->resetDb();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('pl-raw-cfo@example.test');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('P&L raw CFO filter');
        $category = (new PLCategory(Uuid::uuid4()->toString(), $company))
            ->setName('Выбытия')
            ->setFlow(PLFlow::EXPENSE);
        $krasnodarProject = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Продажа компьютеров');
        $rostovProject = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Сервисные услуги');
        $krasnodarCenter = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_KRD', 'Краснодар');
        $rostovCenter = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_RND', 'Ростов');

        foreach ([$user, $company, $category, $krasnodarProject, $rostovProject, $krasnodarCenter, $rostovCenter] as $entity) {
            $em->persist($entity);
        }
        $em->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $krasnodarProject, $krasnodarCenter));
        $em->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $rostovProject, $rostovCenter));
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return [$client, $em, [
            'company' => $company,
            'category' => $category,
            'krasnodarProject' => $krasnodarProject,
            'rostovProject' => $rostovProject,
            'krasnodarCenter' => $krasnodarCenter,
            'rostovCenter' => $rostovCenter,
        ]];
    }

    /**
     * @param array<string, mixed> $graph
     */
    private function createDocument(
        EntityManagerInterface $em,
        array $graph,
        string $number,
        ProjectDirection $project,
        ?string $documentResponsibilityCenterId,
        ?string $operationResponsibilityCenterId,
    ): Document {
        $document = new Document(Uuid::uuid4()->toString(), $graph['company']);
        $document
            ->setDate(new \DateTimeImmutable('2026-07-10'))
            ->setNumber($number)
            ->setType(DocumentType::OTHER)
            ->setProjectDirection($project)
            ->setResponsibilityCenterId($documentResponsibilityCenterId);

        $operation = new DocumentOperation();
        $operation
            ->setCategory($graph['category'])
            ->setAmount('100.00')
            ->setProjectDirection($project)
            ->setResponsibilityCenterId($operationResponsibilityCenterId);
        $document->addOperation($operation);

        $em->persist($document);

        return $document;
    }

    /**
     * @param array<string, mixed> $graph
     */
    private function createDailyTotal(
        EntityManagerInterface $em,
        array $graph,
        ProjectDirection $project,
        string $responsibilityCenterId,
        string $amountExpense,
    ): void {
        $total = new PLDailyTotal(
            Uuid::uuid4()->toString(),
            $graph['company'],
            $project,
            new \DateTimeImmutable('2026-07-10'),
            $graph['category'],
        );
        $total
            ->setAmountIncome('0.00')
            ->setAmountExpense($amountExpense)
            ->setResponsibilityCenterId($responsibilityCenterId);

        $em->persist($total);
    }
}
