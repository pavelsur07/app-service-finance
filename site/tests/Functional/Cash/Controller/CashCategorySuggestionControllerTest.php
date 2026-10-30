<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Enum\Transaction\CashCategorySuggestionStatus;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * ИИ-подсказка статьи в списке операций ДДС: показ, «Принять», «Отклонить», чужая подсказка.
 */
final class CashCategorySuggestionControllerTest extends WebTestCaseBase
{
    public function testListShowsSuggestionAndAcceptSetsCategory(): void
    {
        [$client, $suggestionId, $txId, $categoryId] = $this->prepare(8301);

        $crawler = $client->request('GET', '/finance/cash-transactions/');
        self::assertResponseIsSuccessful();
        $badge = $crawler->filter('.vf-ops-ai-suggestion .badge');
        self::assertCount(1, $badge);
        self::assertStringContainsString('ИИ: Аренда · 91%', $badge->text());

        $client->submit($crawler->filter('.vf-ops-ai-suggestion form')->first()->form());
        self::assertResponseRedirects();

        $this->em()->clear();
        self::assertSame($categoryId, $this->em()->find(CashTransaction::class, $txId)?->getCashflowCategory()?->getId());
        self::assertSame(CashCategorySuggestionStatus::ACCEPTED, $this->em()->find(CashCategorySuggestion::class, $suggestionId)?->getStatus());

        $crawler = $client->request('GET', '/finance/cash-transactions/');
        self::assertCount(0, $crawler->filter('.vf-ops-ai-suggestion'));
    }

    public function testDismissHidesSuggestionAndKeepsOperationUncategorized(): void
    {
        [$client, $suggestionId, $txId] = $this->prepare(8302);

        $crawler = $client->request('GET', '/finance/cash-transactions/');
        $client->submit($crawler->filter('.vf-ops-ai-suggestion form')->last()->form());
        self::assertResponseRedirects();

        $this->em()->clear();
        self::assertNull($this->em()->find(CashTransaction::class, $txId)?->getCashflowCategory());
        self::assertSame(CashCategorySuggestionStatus::DISMISSED, $this->em()->find(CashCategorySuggestion::class, $suggestionId)?->getStatus());
        $crawler = $client->request('GET', '/finance/cash-transactions/');
        self::assertCount(0, $crawler->filter('.vf-ops-ai-suggestion'));
    }

    public function testSuggestionOfAnotherCompanyIsNotAccepted(): void
    {
        [$client, $foreignId, $foreignTxId] = $this->prepare(8303);
        $client->restart();

        $user = UserBuilder::aUser()->withIndex(8304)->build();
        $company = CompanyBuilder::aCompany()->withIndex(8304)->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();
        $this->login($client, $user, (string) $company->getId());

        $client->request('POST', '/finance/cash-transactions/ai-suggestions/'.$foreignId.'/accept', [
            '_token' => $this->csrfToken($client, 'cash_category_suggestion_'.$foreignId),
        ]);
        self::assertResponseRedirects();

        $this->em()->clear();
        self::assertNull($this->em()->find(CashTransaction::class, $foreignTxId)?->getCashflowCategory());
        self::assertSame(CashCategorySuggestionStatus::SUGGESTED, $this->em()->find(CashCategorySuggestion::class, $foreignId)?->getStatus());
    }

    public function testRequestWithoutCsrfTokenChangesNothing(): void
    {
        [$client, $suggestionId, $txId] = $this->prepare(8305);

        $client->request('POST', '/finance/cash-transactions/ai-suggestions/'.$suggestionId.'/accept', ['_token' => 'wrong']);
        self::assertResponseRedirects();

        $this->em()->clear();
        self::assertNull($this->em()->find(CashTransaction::class, $txId)?->getCashflowCategory());
        self::assertSame(CashCategorySuggestionStatus::SUGGESTED, $this->em()->find(CashCategorySuggestion::class, $suggestionId)?->getStatus());
    }

    public function testAutoAppliedCategoryIsMarkedInTheList(): void
    {
        [$client, $suggestionId, $txId, $categoryId] = $this->prepare(8306);
        $em = $this->em();
        $suggestion = $em->find(CashCategorySuggestion::class, $suggestionId);
        $tx = $em->find(CashTransaction::class, $txId);
        $category = $em->find(CashflowCategory::class, $categoryId);
        self::assertNotNull($suggestion);
        self::assertNotNull($tx);
        $tx->setCashflowCategory($category);
        $tx->replaceSplits([new CashTransactionSplit($tx, $category ?? throw new \LogicException(), '85000.00', CashTransactionSplitSource::AUTO)]);
        $suggestion->autoApply(new \DateTimeImmutable());
        $em->flush();
        $em->clear();

        $crawler = $client->request('GET', '/finance/cash-transactions/');

        self::assertResponseIsSuccessful();
        $badge = $crawler->filter('.vf-ops-ai-auto');
        self::assertCount(1, $badge);
        self::assertSame('ИИ', trim($badge->text()));
        self::assertStringContainsString('уверенность 91%', (string) $badge->attr('title'));
        self::assertCount(0, $crawler->filter('.vf-ops-ai-suggestion'));
    }

    /**
     * @return array{0: KernelBrowser, 1: string, 2: string, 3: string}
     */
    private function prepare(int $index): array
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->forCompany($company)
            ->build();
        $category = $this->category($company, $index);
        $tx = new CashTransaction(
            sprintf('66666666-6666-6666-8666-%012d', $index),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '85000.00',
            'RUB',
            new \DateTimeImmutable('-3 days'),
        );
        $tx->setDescription('Оплата аренды офиса');
        $suggestion = new CashCategorySuggestion(
            (string) $company->getId(),
            (string) $tx->getId(),
            sprintf('77777777-7777-4777-8777-%012d', $index),
            new \DateTimeImmutable('-1 hour'),
        );
        $suggestion->suggest((string) $category->getId(), 0.91, 'accepted', 'stub/categorize', 'categorize_transactions/v1', new \DateTimeImmutable());

        foreach ([$user, $company, $account, $category, $tx, $suggestion] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->em()->clear();

        $this->login($client, $user, (string) $company->getId());

        return [$client, $suggestion->getId(), (string) $tx->getId(), (string) $category->getId()];
    }

    private function category(Company $company, int $index): CashflowCategory
    {
        $category = new CashflowCategory(sprintf('55555555-5555-4555-8555-%012d', $index), $company);
        $category->setName('Аренда');
        $category->setOperationType(PaymentPlanType::OUTFLOW);

        return $category;
    }

    private function login(KernelBrowser $client, User $user, string $companyId): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
    }
}
