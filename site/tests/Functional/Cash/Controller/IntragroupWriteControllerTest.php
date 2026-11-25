<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Tests\Builders\Cash\CashIntragroupDecisionBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Fixtures\Cash\IntragroupReportFixture;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class IntragroupWriteControllerTest extends WebTestCaseBase
{
    use IntragroupReportFixture;

    private const PATH = '/finance/reports/cashflow/group/reconciliation';
    private const PERIOD = '?from=2026-11-01&to=2026-11-30';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->em = $this->em();
        $this->connection = $this->em->getConnection();
        $this->createGroupFixture();
        $owner = $this->companies['Альфа']->getUser();
        self::assertNotNull($owner);
        $this->login($owner);
    }

    public function testMemberCompanyCanAssignInnAndPairAndUndoWithRetries(): void
    {
        $this->addTransaction(351, '0001', '2026-11-20', '-150000', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(352, '0003', '2026-11-17', '150000', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();
        $card = $this->cards['Бета']['ООО "Альфа"']->getId();
        $args = ['companyId' => $this->companies['Бета']->getId(), 'counterpartyId' => $card, 'peerCompanyId' => $this->companies['Альфа']->getId(), 'expectedInn' => '7700000011'];
        $this->post('inn', $args);
        self::assertResponseRedirects(self::PATH.self::PERIOD);
        $this->post('inn', $args);
        self::assertResponseRedirects(self::PATH.self::PERIOD);
        self::assertSame('7700000011', $this->connection->fetchOne('SELECT inn FROM counterparty WHERE id = ?', [$card]));

        $id = Uuid::uuid7()->toString();
        $args = ['id' => $id, 'type' => 'PAIR', 'outflowId' => self::transactionId(351), 'inflowId' => self::transactionId(352)];
        $this->post('decision', $args);
        self::assertResponseRedirects(self::PATH.self::PERIOD);
        $this->post('decision', $args);
        self::assertResponseRedirects(self::PATH.self::PERIOD);
        self::assertSame(1, $this->decisionCount($id));
        self::assertSame($this->companies['Альфа']->getId(), $this->connection->fetchOne('SELECT company_id FROM cash_intragroup_decision WHERE id = ?', [$id]));

        $this->post('remove', ['id' => $id]);
        self::assertResponseRedirects(self::PATH.self::PERIOD);
        $this->post('remove', ['id' => $id]);
        self::assertResponseRedirects(self::PATH.self::PERIOD);
        self::assertSame(0, $this->decisionCount($id));
    }

    public function testMismatchedAmountsGiveFeedbackAndPreserveBothTransactions(): void
    {
        $this->fillMissingInns();
        $this->addTransaction(353, '0001', '2026-11-20', '-100', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(354, '0003', '2026-11-17', '90', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();
        $id = Uuid::uuid7()->toString();
        $this->post('decision', ['id' => $id, 'type' => 'PAIR', 'outflowId' => self::transactionId(353), 'inflowId' => self::transactionId(354)]);
        self::assertResponseRedirects(self::PATH.self::PERIOD);
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Суммы не совпадают: 100,00 и 90,00. Пара возможна только при равных суммах', $crawler->filter('body')->text());
        self::assertSame(0, $this->decisionCount($id));
    }

    public function testLegOfCompanyOutsideTheGroupCannotBePaired(): void
    {
        $this->fillMissingInns();
        $this->addTransaction(355, '0001', '2026-11-17', '-100', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(356, '0004', '2026-11-17', '100', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();
        $this->connection->executeStatement('DELETE FROM company_consolidation_member WHERE company_id = ?', [$this->companies['Гамма']->getId()]);

        $id = Uuid::uuid7()->toString();
        $this->post('decision', ['id' => $id, 'type' => 'PAIR', 'outflowId' => self::transactionId(355), 'inflowId' => self::transactionId(356)]);
        self::assertResponseRedirects(self::PATH.self::PERIOD);
        self::assertSame(0, $this->decisionCount($id));
    }

    /** @return iterable<string, array{string}> */
    public static function commands(): iterable
    {
        foreach (['decision', 'remove', 'inn'] as $route) {
            yield $route => [$route];
        }
    }

    #[DataProvider('commands')]
    public function testEachCommandRejectsCsrfTokenForAnotherHead(string $route): void
    {
        $this->post($route, ['_token' => $this->csrfToken($this->client, 'cashflow-group'.$this->companies['Бета']->getId())]);
        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('commands')]
    public function testReadOnlyMemberIsDeniedEveryCommand(string $route): void
    {
        $reader = UserBuilder::aUser()->withIndex(552)->withRoles(['ROLE_COMPANY_USER'])->build();
        $managed = $this->companies['Бета'];
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Чтение', ['finance' => 'read'], $managed);
        $member = CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($managed)->withUser($reader)
            ->withRole(CompanyMember::ROLE_OPERATOR)->withAccessRole($role)->build();
        foreach ([$reader, $role, $member] as $entity) {
            $this->em->persist($entity);
        }
        $decision = CashIntragroupDecisionBuilder::aDecision()->forCompany((string) $this->companies['Альфа']->getId())->build();
        $this->em->persist($decision);
        $this->em->flush();
        $this->login($reader);
        $this->post($route, ['id' => $decision->getId()]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->decisionCount($decision->getId()));
    }

    #[DataProvider('commands')]
    public function testOwnershipOfOnlyPartOfTheGroupIsInsufficient(string $route): void
    {
        $anotherOwner = UserBuilder::aUser()->withIndex(553)->build();
        $this->em->persist($anotherOwner);
        $this->companies['Гамма']->setUser($anotherOwner);
        $this->em->flush();

        $this->post($route, ['id' => Uuid::uuid7()->toString()]);
        self::assertResponseStatusCodeSame(403);
    }

    private function login(User $user): void
    {
        $this->client->loginUser($user);
        $this->setClientSessionValue($this->client, 'active_company_id', $this->companies['Бета']->getId());
    }

    /** @param array<string, mixed> $fields */
    private function post(string $route, array $fields): void
    {
        $this->client->request('POST', self::PATH.'/'.$route.self::PERIOD, $fields + [
            '_token' => $this->csrfToken($this->client, 'cashflow-group'.$this->companies['Альфа']->getId()),
        ]);
    }

    private function decisionCount(string $id): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cash_intragroup_decision WHERE id = ?', [$id]);
    }
}
