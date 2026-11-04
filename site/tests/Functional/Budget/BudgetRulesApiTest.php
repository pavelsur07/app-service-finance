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
 * API правила «X % от строки-базы» (этап 8): задать, снять, сетка, права и CSRF.
 */
final class BudgetRulesApiTest extends WebTestCaseBase
{
    private const COMPANY_ID = '0193eeee-0000-7000-8000-00000000a701';
    private const OTHER_COMPANY_ID = '0193eeee-0000-7000-8000-00000000a702';
    private const PROFIT_ID = '0193eeee-0000-7000-8000-00000000a711';
    private const REVENUE_ID = '0193eeee-0000-7000-8000-00000000a712';
    private const RENT_ID = '0193eeee-0000-7000-8000-00000000a713';
    private const PROJECT_ID = '0193eeee-0000-7000-8000-00000000a721';
    private const SHOP_ID = '0193eeee-0000-7000-8000-00000000a722';

    private KernelBrowser $client;
    private User $owner;
    private User $writer;
    private User $reader;
    private string $centerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-rules-owner@example.test')->build();
        $this->writer = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-rules-writer@example.test')->build();
        $this->reader = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-rules-reader@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($this->owner)->build();
        $writeRole = new CompanyRole(Uuid::uuid7()->toString(), 'Финансы — запись', ['finance' => 'write'], $company);
        $readRole = new CompanyRole(Uuid::uuid7()->toString(), 'Финансы — чтение', ['finance' => 'read'], $company);
        $profit = PLCategoryBuilder::aPLCategory()->withId(self::PROFIT_ID)->forCompany($company)->withName('Прибыль')->withCode('PROFIT')->withType(PLCategoryType::SUBTOTAL)->build();
        $revenue = PLCategoryBuilder::aPLCategory()->withId(self::REVENUE_ID)->forCompany($company)->withName('Выручка')->withCode('REV')->withFlow(PLFlow::INCOME)->withParent($profit)->build();
        $rent = PLCategoryBuilder::aPLCategory()->withId(self::RENT_ID)->forCompany($company)->withName('=Аренда')->withCode('RENT')->withFlow(PLFlow::EXPENSE)->withParent($profit)->build();
        $project = new ProjectDirection(self::PROJECT_ID, $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $shop = new ProjectDirection(self::SHOP_ID, $company, 'Магазин');
        $center = new FinancialResponsibilityCenter(self::COMPANY_ID, FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $this->centerId = $center->getId();
        $otherOwner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-rules-other@example.test')->build();

        foreach ([
            $this->owner, $this->writer, $this->reader, $company, $writeRole, $readRole,
            CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($this->writer)->withAccessRole($writeRole)->build(),
            CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($this->reader)->withAccessRole($readRole)->build(),
            $profit, $revenue, $rent, $project, $shop, $center,
            new FinancialResponsibilityCenterProject(self::COMPANY_ID, $project, $center),
            new FinancialResponsibilityCenterProject(self::COMPANY_ID, $shop, $center),
            $otherOwner, CompanyBuilder::aCompany()->withId(self::OTHER_COMPANY_ID)->withOwner($otherOwner)->build(),
        ] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
    }

    public function testSetRuleShowsInGridAndDeleteKeepsAmounts(): void
    {
        $id = $this->version(BudgetVersionStatus::DRAFT);
        $this->line($id, self::REVENUE_ID, '2027-01-01', '1000.00');
        $this->login($this->writer);

        $this->post($id, 'rules', ['project' => self::PROJECT_ID, 'center' => $this->centerId, 'categoryId' => self::RENT_ID, 'baseCategoryId' => self::REVENUE_ID, 'percent' => '12,5']);
        self::assertResponseIsSuccessful();
        self::assertSame(['changed' => 1], $this->json());

        $this->client->request('GET', sprintf('/finance/budget/versions/%s/grid?project=%s&center=%s', $id, self::PROJECT_ID, $this->centerId));
        $grid = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($grid);
        self::assertIsArray($grid['rows']);
        $rent = array_values(array_filter($grid['rows'], static fn (array $row): bool => self::RENT_ID === $row['id']))[0];
        self::assertFalse($rent['editable']);
        self::assertSame(['percent' => '12.50', 'baseCategoryId' => self::REVENUE_ID, 'baseName' => 'Выручка'], $rent['rule']);
        self::assertSame('-125.00', $rent['values']['2027-01']);

        $this->post($id, 'rules/delete', ['project' => self::PROJECT_ID, 'center' => $this->centerId, 'categoryId' => self::RENT_ID]);
        self::assertSame(['removed' => true], $this->json());
        self::assertSame('manual', $this->em()->getConnection()->fetchOne('SELECT source FROM budget_lines WHERE version_id = ? AND pl_category_id = ?', [$id, self::RENT_ID]));
    }

    public function testRejectsBadRequests(): void
    {
        $draft = $this->version(BudgetVersionStatus::DRAFT);
        $approved = $this->version(BudgetVersionStatus::APPROVED);
        $foreign = $this->version(BudgetVersionStatus::DRAFT, self::OTHER_COMPANY_ID);
        $body = ['project' => self::PROJECT_ID, 'center' => $this->centerId, 'categoryId' => self::RENT_ID, 'baseCategoryId' => self::REVENUE_ID, 'percent' => '10'];
        $this->login($this->writer);

        $this->post($approved, 'rules', $body);
        self::assertResponseStatusCodeSame(409);
        $this->post($foreign, 'rules', $body);
        self::assertResponseStatusCodeSame(404);
        $this->post($draft, 'rules', ['percent' => 'abc'] + $body);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('budget_invalid_input', $this->json()['error']['code']);
        $this->post($draft, 'rules', $body, 'bad');
        self::assertResponseStatusCodeSame(403);

        $this->login($this->reader);
        $this->post($draft, 'rules', $body);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM budget_rules'));
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $versionId, string $path, array $body, ?string $token = null): void
    {
        $this->client->request(
            'POST',
            '/finance/budget/versions/'.$versionId.'/'.$path,
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

    private function line(string $versionId, string $categoryId, string $month, string $amount): void
    {
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO budget_lines (id, company_id, version_id, pl_category_id, month, project_direction_id, responsibility_center_id, amount, source, comment, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', NULL, NOW())",
            [Uuid::uuid7()->toString(), self::COMPANY_ID, $versionId, $categoryId, $month, self::PROJECT_ID, $this->centerId, $amount],
        );
    }

    private function version(BudgetVersionStatus $status, string $companyId = self::COMPANY_ID): string
    {
        $id = Uuid::uuid7()->toString();
        $this->em()->persist(BudgetVersionBuilder::aVersion()->withId($id)->forCompany($companyId)->withYear(2027)->withStatus($status)->build());
        $this->em()->flush();
        $this->em()->clear();

        return $id;
    }

    private function login(User $user): void
    {
        $this->client->loginUser($user);
        $this->setClientSessionValue($this->client, 'active_company_id', self::COMPANY_ID);
    }
}
