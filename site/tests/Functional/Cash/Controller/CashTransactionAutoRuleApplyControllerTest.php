<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;
use App\Cash\Message\EnqueueAutoRulesForRange;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CashTransactionAutoRuleApplyControllerTest extends WebTestCaseBase
{
    public function testLaunchUsesCurrentCalendarYear(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('auto-rule-apply@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $today = new \DateTimeImmutable('today');
        $yearStart = $today->setDate((int) $today->format('Y'), 1, 1);

        $crawler = $client->request('GET', '/finance/cash-transactions/auto-rule-apply');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-body', 'с начала текущего года');
        self::assertSelectorTextContains('.alert-info', sprintf(
            'Период запуска: %s — %s.',
            $yearStart->format('d.m.Y'),
            $today->format('d.m.Y'),
        ));

        $client->submit($crawler->selectButton('Запустить')->form());

        self::assertResponseRedirects('/finance/cash-transactions/auto-rule-apply');

        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_pipeline');
        self::assertCount(1, $transport->getSent());

        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(EnqueueAutoRulesForRange::class, $message);
        self::assertSame($company->getId(), $message->companyId);
        self::assertEquals($yearStart, $message->from);
        self::assertEquals($today, $message->to);
        self::assertSame(CashTransactionAutoRuleApplyMode::SAFE, $message->mode);
        self::assertSame($owner->getId(), $message->initiatedByUserId);
    }

    public function testUnsafeModeRequiresConfirmation(): void
    {
        [$client, $crawler] = $this->openApplyPage('unsafe-no-confirm@example.test');

        $client->submit($crawler->selectButton('Запустить')->form([
            'mode' => CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED->value,
        ]));

        self::assertResponseRedirects('/finance/cash-transactions/auto-rule-apply');
        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_pipeline');
        self::assertCount(0, $transport->getSent());
    }

    public function testUnsafeModeIsDispatchedWithActor(): void
    {
        [$client, $crawler, $owner] = $this->openApplyPage('unsafe-confirmed@example.test');

        $client->submit($crawler->selectButton('Запустить')->form([
            'mode' => CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED->value,
            'confirm_replace' => '1',
        ]));

        self::assertResponseRedirects('/finance/cash-transactions/auto-rule-apply');
        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_pipeline');
        self::assertCount(1, $transport->getSent());
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(EnqueueAutoRulesForRange::class, $message);
        self::assertSame(CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED, $message->mode);
        self::assertSame($owner->getId(), $message->initiatedByUserId);
    }

    /** @return array{\Symfony\Bundle\FrameworkBundle\KernelBrowser, \Symfony\Component\DomCrawler\Crawler, \App\Company\Entity\User} */
    private function openApplyPage(string $email): array
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return [$client, $client->request('GET', '/finance/cash-transactions/auto-rule-apply'), $owner];
    }
}
