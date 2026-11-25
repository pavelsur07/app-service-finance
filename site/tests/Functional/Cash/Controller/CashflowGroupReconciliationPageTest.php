<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Application\Report\IntragroupDecisionAction;
use App\Cash\Enum\Transaction\IntragroupDecisionType;
use App\Tests\Fixtures\Cash\IntragroupReportFixture;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

final class CashflowGroupReconciliationPageTest extends WebTestCaseBase
{
    use IntragroupReportFixture;

    private const URL = '/finance/reports/cashflow/group/reconciliation';
    private EntityManagerInterface $em;
    private Connection $connection;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->em = $this->em();
        $this->connection = $this->em->getConnection();
        $this->createGroupFixture();
        $owner = $this->companies['Альфа']->getUser();
        self::assertNotNull($owner);
        $this->client->loginUser($owner);
        $this->setClientSessionValue($this->client, 'active_company_id', (string) $this->companies['Бета']->getId());
    }

    public function testShowsAllFourTaxIdChoicesForNovember(): void
    {
        $this->loadNovemberTransactions();
        $page = $this->open();
        $forms = $page->filter('#candidates form');
        self::assertCount(4, $forms);
        $values = $forms->first()->form()->getValues();
        foreach (['companyId', 'counterpartyId', 'peerCompanyId', 'expectedInn', '_token'] as $field) {
            self::assertNotEmpty($values[$field]);
        }
        self::assertStringContainsString('ООО «Альфа»', $page->filter('#candidates')->text());
        self::assertStringContainsString('7700000011', $page->filter('#candidates')->text());
    }

    public function testChangedPeerInnRequiresNewConfirmation(): void
    {
        $this->loadNovemberTransactions();
        $form = $this->open()->filter('#candidates form')->first()->form();
        $fields = $form->getValues();
        $this->connection->executeStatement('UPDATE companies SET inn = ? WHERE id = ?', ['7700000088', $fields['peerCompanyId']]);
        $this->client->submit($form);
        $after = $this->client->followRedirect();
        self::assertStringContainsString('Предложение ИНН устарело', $after->text());
        self::assertEmpty($this->connection->fetchOne('SELECT inn FROM counterparty WHERE id = ?', [$fields['counterpartyId']]));
    }

    public function testPeriodHintsAmountsAndManualMatchingFormAcrossPeriodBoundary(): void
    {
        $this->loadNovemberTransactions();
        $this->fillMissingInns();
        $this->addTransaction(35, '0002', '2026-11-19', '-150000', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->em->flush();
        $page = $this->open();
        self::assertCount(5, $page->filter('#pairs tbody tr'));
        self::assertCount(2, $page->filter('#unpaired tbody tr'));
        $control = str_replace("\u{00A0}", ' ', $page->filter('#control')->text());
        foreach (['3 667 000,00', '917 000,00', '2 750 000,00'] as $amount) {
            self::assertStringContainsString($amount, $control);
        }
        self::assertStringContainsString('Альфа · ВТБ ****0001 — данные по 12.11', $page->filter('#unpaired')->text());
        $matchLink = $page->filter('#unpaired a[href*="match='.self::transactionId(23).'"]')->link();
        self::assertStringContainsString('to=2026-11-18', $matchLink->getUri());
        $manual = $this->client->click($matchLink)->filter('#match');
        $form = $manual->selectButton('Сопоставить')->form(['outflowId' => self::transactionId(35)]);
        $values = $form->getValues();
        self::assertSame('PAIR', $values['type']);
        self::assertSame(self::transactionId(23), $values['inflowId']);
        self::assertNotEmpty($values['id']);
        self::assertNotEmpty($values['_token']);
        self::assertStringContainsString('поступление раньше выплаты', $manual->text());
        self::assertStringContainsString('from=2026-11-01', $form->getUri());
        self::assertStringContainsString('to=2026-11-18', $form->getUri());
    }

    public function testMoneyInTransitLinksToCounterlegAndKeepsNovember(): void
    {
        $this->fillMissingInns();
        $this->addTransaction(51, '0001', '2026-11-30', '-12.34', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(52, '0003', '2026-12-02', '12.34', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();
        $page = $this->open(to: '2026-11-30');
        $transit = $page->filter('#in-transit');
        self::assertStringContainsString('30.11.2026', $transit->text());
        self::assertStringContainsString('02.12.2026', $transit->text());
        $link = $transit->filter('a[href*="operation='.self::transactionId(52).'"]')->link();
        self::assertStringContainsString('to=2026-11-30', $link->getUri());
        $operation = $this->client->click($link)->filter('#operation');
        self::assertStringContainsString('Бета · Сбер ****0003', $operation->text());
        self::assertStringContainsString('12,34', $operation->text());
        self::assertStringContainsString('02.12.2026', $operation->text());
    }

    public function testManualAutoUnpairAndStaleDecisionsHaveDistinctActions(): void
    {
        $this->loadNovemberTransactions();
        $this->fillMissingInns();
        $action = self::getContainer()->get(IntragroupDecisionAction::class);
        $head = $this->companies['Альфа'];
        $owner = $head->getUser();
        self::assertNotNull($owner);
        $stale = Uuid::uuid7()->toString();
        $manual = Uuid::uuid7()->toString();
        $unpair = Uuid::uuid7()->toString();
        foreach ([[$stale, 1, 19, IntragroupDecisionType::PAIR], [$manual, 4, 20, IntragroupDecisionType::PAIR], [$unpair, 24, 15, IntragroupDecisionType::UNPAIR]] as [$id, $out, $in, $type]) {
            $action->save((string) $head->getId(), (string) $owner->getId(), $id, $type, self::transactionId($out), self::transactionId($in));
        }
        $this->connection->update('cash_transaction', ['deleted_at' => '2026-11-20'], ['id' => self::transactionId(19)]);
        $page = $this->open();
        self::assertCount(1, $page->filter('#pairs input[name="id"][value="'.$manual.'"]'));
        self::assertGreaterThan(0, $page->filter('#pairs input[name="type"][value="UNPAIR"]')->count());
        self::assertCount(1, $page->filter('#unpair-decisions input[name="id"][value="'.$unpair.'"]'));
        self::assertCount(1, $page->filter('#stale-decisions input[name="id"][value="'.$stale.'"]'));
        self::assertCount(0, $page->filter('#unpaired a[href*="match='.self::transactionId(1).'"]'));
        self::assertCount(1, $page->filter('#unpaired a[href="#stale-decisions"]'));
    }

    private function open(string $to = '2026-11-18'): Crawler
    {
        $page = $this->client->request('GET', self::URL.'?from=2026-11-01&to='.$to);
        self::assertResponseIsSuccessful();

        return $page;
    }
}
