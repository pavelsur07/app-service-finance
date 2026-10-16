<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Контрагент в форме операции ДДС — окно выбора как у пилюли фильтра «Контрагент» (browse):
 * docs/design-system/components/entity-picker.md.
 */
final class CashTransactionCounterpartyPickerTest extends WebTestCaseBase
{
    public function testNewTransactionUsesBrowseWindowAndSavesChosenCounterparty(): void
    {
        $client = static::createClient();
        [$user, $company, $account, $project, $center] = $this->seed();
        $counterparty = $this->persistCounterparty($company, 'ООО Ромашка', false);
        $this->login($client, $user, $company);

        $crawler = $client->request('GET', '/finance/cash-transactions/new');
        self::assertResponseIsSuccessful();
        $picker = $crawler->filter('select[name="cash_transaction[counterpartyId]"]')->closest('[data-cp-picker]');
        self::assertNotNull($picker);
        self::assertSame('1', $picker->attr('data-cp-browse'));
        self::assertSame(
            $counterparty->getType()->value,
            $crawler->filter(sprintf('select[name="cash_transaction[counterpartyId]"] option[value="%s"]', $counterparty->getId()))->attr('data-type'),
        );

        $form = $crawler->selectButton('Сохранить')->form();
        $form['cash_transaction[occurredAt]'] = '2026-10-16';
        $accountField = $form['cash_transaction[moneyAccount]'];
        self::assertInstanceOf(ChoiceFormField::class, $accountField);
        $accountField->select((string) $account->getId());
        $form['cash_transaction[amount]'] = '1500';
        foreach (['projectDirection' => $project->getId(), 'responsibilityCenterId' => $center->getId()] as $name => $value) {
            $field = $form[sprintf('cash_transaction[%s]', $name)];
            self::assertInstanceOf(ChoiceFormField::class, $field);
            $field->select((string) $value);
        }
        $counterpartyField = $form['cash_transaction[counterpartyId]'];
        self::assertInstanceOf(ChoiceFormField::class, $counterpartyField);
        $counterpartyField->select($counterparty->getId());
        $client->submit($form);

        self::assertResponseRedirects();
        $saved = $this->em()->getRepository(CashTransaction::class)->findBy(['company' => $company]);
        self::assertCount(1, $saved);
        self::assertInstanceOf(CashTransaction::class, $saved[0]);
        self::assertSame($counterparty->getId(), $saved[0]->getCounterparty()?->getId());
    }

    /**
     * Архивный контрагент операции остаётся среди вариантов: окно показывает его первым, а
     * в список «до ввода» других архивных не берёт (data-archived).
     */
    public function testEditKeepsArchivedCounterpartyMarkedForTheWindow(): void
    {
        $client = static::createClient();
        [$user, $company, $account] = $this->seed();
        $archived = $this->persistCounterparty($company, 'Архивный поставщик', true);
        $tx = new CashTransaction(Uuid::uuid4()->toString(), $company, $account, CashDirection::OUTFLOW, '1000.00', 'RUB', new \DateTimeImmutable('2026-10-01'));
        $tx->setCounterparty($archived);
        $this->em()->persist($tx);
        $this->em()->flush();
        $this->login($client, $user, $company);

        $crawler = $client->request('GET', sprintf('/finance/cash-transactions/%s/edit', $tx->getId()));
        self::assertResponseIsSuccessful();

        $option = $crawler->filter(sprintf('select[name="cash_transaction[counterpartyId]"] option[value="%s"]', $archived->getId()));
        self::assertCount(1, $option);
        self::assertSame('selected', $option->attr('selected'));
        self::assertSame('1', $option->attr('data-archived'));
    }

    /**
     * @return array{0: User, 1: Company, 2: MoneyAccount, 3: ProjectDirection, 4: FinancialResponsibilityCenter}
     */
    private function seed(): array
    {
        $user = UserBuilder::aUser()->withIndex(random_int(1000, 9999))->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(random_int(1000, 9999))->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid4()->toString())->forCompany($company)->build();
        // Ручная операция при сохранении получает системную пару «Общий проект × Общий ЦФО».
        $systemProject = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $systemCenter = new FinancialResponsibilityCenter((string) $company->getId(), FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        foreach ([$user, $company, $account, $systemProject, $systemCenter, new FinancialResponsibilityCenterProject((string) $company->getId(), $systemProject, $systemCenter)] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        return [$user, $company, $account, $systemProject, $systemCenter];
    }

    private function persistCounterparty(Company $company, string $name, bool $archived): Counterparty
    {
        $builder = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($company)->withName($name);
        $counterparty = ($archived ? $builder->asArchived() : $builder)->build();
        $this->em()->persist($counterparty);
        $this->em()->flush();

        return $counterparty;
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
