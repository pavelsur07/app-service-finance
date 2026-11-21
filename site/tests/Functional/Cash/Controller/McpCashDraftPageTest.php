<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Application\DTO\PaymentIntake\CreateMcpCashDraft;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class McpCashDraftPageTest extends WebTestCaseBase
{
    public function testOnlyAnExplicitConfirmationOfTheShownDraftCreatesAFact(): void
    {
        $client = self::createClient();
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $companyId = (string) $company->getId();
        $account = new MoneyAccount(Uuid::uuid7()->toString(), $company, MoneyAccountType::BANK, 'Основной счёт', 'RUB');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $project = new ProjectDirection(Uuid::uuid7()->toString(), $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $center = new FinancialResponsibilityCenter($companyId, FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $colleague = UserBuilder::aUser()->withIndex(2)->build();
        $membership = new CompanyMember(Uuid::uuid7()->toString(), $company, $colleague, CompanyMember::ROLE_OWNER);
        foreach ([$owner, $company, $account, $project, $center, new FinancialResponsibilityCenterProject($companyId, $project, $center), $colleague, $membership] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $cash = self::getContainer()->get(CashFacade::class);
        $draft = $cash->createMcpCashDraft(new CreateMcpCashDraft(
            $companyId, (string) $owner->getId(), Uuid::uuid7()->toString(), 'test-42',
            (string) $account->getId(), new \DateTimeImmutable('2026-10-10'), CashDirection::OUTFLOW, '1250.00',
        ));
        $toCancel = $cash->createMcpCashDraft(new CreateMcpCashDraft(
            $companyId, (string) $owner->getId(), Uuid::uuid7()->toString(), 'test-cancel',
            (string) $account->getId(), new \DateTimeImmutable('2026-10-10'), CashDirection::OUTFLOW, '500.00',
            description: '<script id="mcp-test">alert(1)</script>',
        ));
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
        $index = '/finance/cash-transactions/mcp-drafts';
        $client->request('GET', $index);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section[aria-labelledby=mcp-draft-title]', 'test-42');
        $path = $index.'/'.$draft->draftId;
        $page = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section[aria-labelledby=mcp-draft-title]', 'Основной счёт');
        self::assertSelectorTextContains('section[aria-labelledby=mcp-draft-title]', '10.10.2026');
        self::assertSelectorTextContains('section[aria-labelledby=mcp-draft-title]', 'test-42');
        $form = $page->selectButton('Подтвердить оплату')->form();
        $params = $form->getPhpValues();

        $client->request('POST', $form->getUri(), ['_token' => 'wrong', 'paid' => '1'] + $params);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $form->getUri(), $params);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $form->getUri(), ['paid' => '1', 'revision' => '99'] + $params);
        self::assertResponseRedirects($path);
        self::assertSame(0, $this->factCount($companyId));

        $client->request('POST', $form->getUri(), ['paid' => '1'] + $params);
        self::assertResponseRedirects($path);
        self::assertSame(1, $this->factCount($companyId));
        $client->followRedirect();
        self::assertSelectorTextContains('section[aria-labelledby=mcp-draft-title]', 'Оплата подтверждена');

        $client->clickLink('Открыть операцию ДДС');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('dl.row', 'MCP');
        $client->request('GET', $index);
        $client->clickLink('Завершённые');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section[aria-labelledby=mcp-draft-title]', 'test-42');

        $cancelPath = $index.'/'.$toCancel->draftId;
        $page = $client->request('GET', $cancelPath);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('script#mcp-test');
        self::assertSelectorTextContains('section[aria-labelledby=mcp-draft-title]', '<script id="mcp-test">');
        $cancel = $page->selectButton('Отменить черновик')->form();
        $cancelParams = $cancel->getPhpValues();
        $client->loginUser($colleague);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
        $client->request('GET', $cancelPath);
        self::assertResponseStatusCodeSame(404);
        $token = $this->csrfToken($client, 'mcp_draft_confirm_'.$toCancel->draftId);
        $client->request('POST', $cancelPath.'/confirm', ['_token' => $token, 'paid' => '1', 'revision' => '1']);
        self::assertResponseStatusCodeSame(404);
        $token = $this->csrfToken($client, 'mcp_draft_cancel_'.$toCancel->draftId);
        $client->request('POST', $cancel->getUri(), ['_token' => $token] + $cancelParams);
        self::assertResponseStatusCodeSame(404);

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
        $page = $client->request('GET', $cancelPath);
        $client->submit($page->selectButton('Отменить черновик')->form());
        self::assertResponseRedirects($cancelPath);
        self::assertSame(1, $this->factCount($companyId));
        $client->followRedirect();
        self::assertSelectorTextContains('section[aria-labelledby=mcp-draft-title]', 'Черновик отменён');
    }

    private function factCount(string $companyId): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id = ? AND import_source = ?', [$companyId, 'mcp']);
    }
}
