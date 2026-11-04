<?php

declare(strict_types=1);

namespace App\Tests\Functional\Budget;

use App\Budget\Enum\BudgetVersionStatus;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Budget\BudgetVersionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Шапка экрана варианта (этап 11): переименование черновика и сетка по одному измерению разреза.
 */
final class BudgetVersionHeaderApiTest extends WebTestCaseBase
{
    private const COMPANY_ID = '0193eeee-0000-7000-8000-00000000b101';
    private const OTHER_COMPANY_ID = '0193eeee-0000-7000-8000-00000000b102';
    private const PROFIT_ID = '0193eeee-0000-7000-8000-00000000b111';
    private const REVENUE_ID = '0193eeee-0000-7000-8000-00000000b112';
    private const PROJECT_ID = '0193eeee-0000-7000-8000-00000000b121';
    private const SHOP_ID = '0193eeee-0000-7000-8000-00000000b122';

    private KernelBrowser $client;
    private User $owner;
    private User $reader;
    private string $centerId;
    private string $lastVersionId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-head-owner@example.test')->build();
        $this->reader = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-head-reader@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($this->owner)->build();
        $readRole = new CompanyRole(Uuid::uuid7()->toString(), 'Финансы — чтение', ['finance' => 'read'], $company);
        $profit = PLCategoryBuilder::aPLCategory()->withId(self::PROFIT_ID)->forCompany($company)->withName('Прибыль')->withCode('PROFIT')->withType(PLCategoryType::SUBTOTAL)->build();
        $revenue = PLCategoryBuilder::aPLCategory()->withId(self::REVENUE_ID)->forCompany($company)->withName('Выручка')->withCode('REV')->withFlow(PLFlow::INCOME)->withParent($profit)->build();
        $project = new ProjectDirection(self::PROJECT_ID, $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $shop = new ProjectDirection(self::SHOP_ID, $company, 'Магазин');
        $center = new FinancialResponsibilityCenter(self::COMPANY_ID, FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $this->centerId = $center->getId();
        $otherOwner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-head-other@example.test')->build();

        foreach ([
            $this->owner, $this->reader, $company, $readRole,
            CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($this->reader)->withAccessRole($readRole)->build(),
            $profit, $revenue, $project, $shop, $center,
            new FinancialResponsibilityCenterProject(self::COMPANY_ID, $project, $center),
            new FinancialResponsibilityCenterProject(self::COMPANY_ID, $shop, $center),
            $otherOwner, CompanyBuilder::aCompany()->withId(self::OTHER_COMPANY_ID)->withOwner($otherOwner)->build(),
        ] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
    }

    public function testOwnerRenamesDraftAndRepeatKeepsName(): void
    {
        $id = $this->version(BudgetVersionStatus::DRAFT);
        $this->login($this->owner);

        $this->rename($id, ['name' => '  Базовый 2027  ']);
        self::assertResponseIsSuccessful();
        self::assertSame(['name' => 'Базовый 2027'], $this->json());
        self::assertSame('Базовый 2027', $this->nameOf($id));

        $this->rename($id, ['name' => 'Базовый 2027']);
        self::assertResponseIsSuccessful();
        self::assertSame('Базовый 2027', $this->nameOf($id));
    }

    public function testRenameRejectsNotDraftBadInputReaderCsrfAndForeignVersion(): void
    {
        $approved = $this->version(BudgetVersionStatus::APPROVED);
        $draft = $this->version(BudgetVersionStatus::DRAFT);
        $foreign = $this->version(BudgetVersionStatus::DRAFT, self::OTHER_COMPANY_ID);
        $this->login($this->owner);

        $this->rename($approved, ['name' => 'Новое']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('budget_version_not_editable', $this->json()['error']['code'] ?? null);
        self::assertSame('Вариант', $this->nameOf($approved));

        $this->rename($draft, ['name' => '   ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('budget_invalid_input', $this->json()['error']['code'] ?? null);

        $this->rename($draft, ['name' => str_repeat('я', 121)]);
        self::assertResponseStatusCodeSame(422);

        $this->rename($draft, ['title' => 'x']);
        self::assertResponseStatusCodeSame(422);

        $this->rename($draft, ['name' => 'Новое'], 'bad-token');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('invalid_csrf', $this->json()['error']['code'] ?? null);

        $this->rename($foreign, ['name' => 'Новое']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('budget_version_not_found', $this->json()['error']['code'] ?? null);

        $this->login($this->reader);
        $this->rename($draft, ['name' => 'Новое']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Вариант', $this->nameOf($draft));
    }

    public function testGridSumsOneDimensionReadOnlyAndRejectsUnknownValue(): void
    {
        $id = $this->version(BudgetVersionStatus::DRAFT);
        $this->line($id, self::PROJECT_ID, '2027-01-01', '100.00');
        $this->line($id, self::SHOP_ID, '2027-01-01', '30.00');
        $this->login($this->owner);

        self::assertSame('30.00', $this->revenueJanuary(sprintf('project=%s&center=all', self::SHOP_ID)));
        self::assertSame('130.00', $this->revenueJanuary(sprintf('project=all&center=%s', $this->centerId)));
        self::assertSame('130.00', $this->revenueJanuary('project=all&center=all'));
        self::assertSame('100.00', $this->revenueJanuary(sprintf('project=%s&center=%s', self::PROJECT_ID, $this->centerId)));

        foreach ([
            sprintf('project=%s&center=all', Uuid::uuid7()->toString()),
            sprintf('project=all&center=%s', Uuid::uuid7()->toString()),
        ] as $query) {
            $this->client->request('GET', sprintf('/finance/budget/versions/%s/grid?%s', $id, $query));
            self::assertResponseStatusCodeSame(422);
            self::assertSame('budget_invalid_pair', $this->json()['error']['code'] ?? null);
        }
    }

    private function revenueJanuary(string $query): string
    {
        $this->client->request('GET', sprintf('/finance/budget/versions/%s/grid?%s', $this->lastVersionId, $query));
        self::assertResponseIsSuccessful();
        $grid = $this->json();
        self::assertIsArray($grid['rows']);
        foreach ($grid['rows'] as $row) {
            if (self::REVENUE_ID === $row['id']) {
                return (string) $row['values']['2027-01'];
            }
        }
        self::fail('Нет строки выручки');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function rename(string $versionId, array $body, ?string $token = null): void
    {
        $this->client->request(
            'POST',
            '/finance/budget/versions/'.$versionId.'/name',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token ?? $this->csrfToken($this->client, 'budget_cells')],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);

        return $body;
    }

    private function nameOf(string $versionId): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT name FROM budget_versions WHERE id = ?', [$versionId]);
    }

    private function line(string $versionId, string $projectId, string $month, string $amount): void
    {
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO budget_lines (id, company_id, version_id, pl_category_id, month, project_direction_id, responsibility_center_id, amount, source, comment, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', NULL, NOW())",
            [Uuid::uuid7()->toString(), self::COMPANY_ID, $versionId, self::REVENUE_ID, $month, $projectId, $this->centerId, $amount],
        );
    }

    private function version(BudgetVersionStatus $status, string $companyId = self::COMPANY_ID): string
    {
        $id = Uuid::uuid7()->toString();
        $this->em()->persist(BudgetVersionBuilder::aVersion()->withId($id)->forCompany($companyId)->withYear(2027)->withName('Вариант')->withStatus($status)->build());
        $this->em()->flush();
        $this->em()->clear();
        $this->lastVersionId = $id;

        return $id;
    }

    private function login(User $user): void
    {
        $this->client->loginUser($user);
        $this->setClientSessionValue($this->client, 'active_company_id', self::COMPANY_ID);
    }
}
