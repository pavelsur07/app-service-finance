<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\Report\AssignIntragroupTaxIdAction;
use App\Cash\Application\Report\IntragroupGroupAccess;
use App\Cash\Application\Report\IntragroupTaxIds;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\ConsolidationGroupFacadeInterface;
use App\Company\Facade\CounterpartyFacade;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\ConsolidationMembershipBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class IntragroupTaxIdsTest extends IntegrationTestCase
{
    private User $owner;
    private Company $head;
    private Company $member;
    private Company $third;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = UserBuilder::aUser()->withIndex(396)->build();
        $this->em->persist($this->owner);
        $this->head = $this->company('Альфа', '7700000011');
        $this->member = $this->company('Бета', '7700000022');
        $this->third = $this->company('Гамма', '770000003333');
        $this->em->flush();
        foreach ($this->members() as $id) {
            $this->em->persist(ConsolidationMembershipBuilder::aMembership()->forCompany($id)->withHead((string) $this->head->getId())->build());
        }
        $this->em->flush();
    }

    public function testCandidatesAndAssignmentWithDuplicatesAndIdempotentRetry(): void
    {
        $cards = [
            $this->card($this->head, 'ООО «Гамма»'),
            $this->card($this->member, 'ООО "Альфа"'),
            $this->card($this->member, 'ООО «Альфа»'),
            $this->card($this->third, '7700000011, подпись банка'),
        ];
        $this->card($this->head, '7700000011, свой счёт');
        $this->card($this->member, 'ООО «Гамма»', '7700000044');
        $this->card($this->member, '7700000011000, номер из 13 цифр');
        $this->card($this->member, 'Внешний контрагент');
        $this->operation($cards[1], '2026-11-01');
        $this->operation($cards[1], '2026-11-18');
        $this->operation($cards[1], '2026-11-19');
        $this->operation($cards[1], '2026-11-05', deleted: true);
        $this->operation($cards[1], '2026-11-05', currency: 'USD');
        $this->em->flush();

        $taxIds = $this->taxIds();
        $from = new \DateTimeImmutable('2026-11-01');
        $to = new \DateTimeImmutable('2026-11-18');
        $proposals = array_column($taxIds->candidates($this->members(), $from, $to), null, 'counterpartyId');
        self::assertEqualsCanonicalizing(array_map(static fn (Counterparty $cp) => $cp->getId(), $cards), array_keys($proposals));
        self::assertSame(2, $proposals[$cards[1]->getId()]->operationCount);
        self::assertSame(0, $proposals[$cards[2]->getId()]->operationCount);
        self::assertSame('770000003333', $proposals[$cards[0]->getId()]->inn);
        foreach ($proposals as $proposal) {
            $this->assign($taxIds, $proposal->companyId, $proposal->counterpartyId, $proposal->peerCompanyId);
            $this->connection->executeStatement('UPDATE counterparty SET updated_at = ? WHERE id = ?', ['2026-01-01 01:02:03', $proposal->counterpartyId]);
            $changed = $this->connection->fetchOne('SELECT updated_at FROM counterparty WHERE id = ?', [$proposal->counterpartyId]);
            $this->assign($taxIds, $proposal->companyId, $proposal->counterpartyId, $proposal->peerCompanyId);
            self::assertSame($changed, $this->connection->fetchOne('SELECT updated_at FROM counterparty WHERE id = ?', [$proposal->counterpartyId]));
            self::assertSame($proposal->inn, $this->connection->fetchOne('SELECT inn FROM counterparty WHERE id = ?', [$proposal->counterpartyId]));
        }
        self::assertSame([], $taxIds->candidates($this->members(), $from, $to));
    }

    public function testAmbiguousNameAndOwnInnNeverBecomeSuggestions(): void
    {
        $this->head->setInn((string) $this->member->getInn());
        $this->head->setName('«Гамма» ООО');
        $this->card($this->member, 'ООО «Гамма»');
        $this->card($this->member, '7700000022, своя компания');
        $prefix = $this->card($this->member, '770000003333, владелец счёта');
        $this->em->flush();

        $taxIds = $this->taxIds();
        $suggestions = $taxIds->candidates($this->members(), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-18'));
        self::assertSame([$prefix->getId()], array_column($suggestions, 'counterpartyId'));
        self::assertSame($this->third->getId(), $suggestions[0]->peerCompanyId);
    }

    public function testACompanyNameWithAnInnSharedByTwoPeersIsAmbiguous(): void
    {
        $this->third->setInn((string) $this->head->getInn());
        $this->card($this->member, 'ООО «Альфа»');
        $this->em->flush();

        self::assertSame([], $this->taxIds()->candidates($this->members(), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-18')));
    }

    public function testChangedTaxIdIsNeverOverwrittenEvenWhenTheCardWasAlreadyLoaded(): void
    {
        $card = $this->card($this->member, 'ООО «Альфа»');
        $this->em->flush();
        $taxIds = $this->taxIds();
        $candidates = $taxIds->candidates($this->members(), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-18'));
        self::assertCount(1, $candidates);
        $this->connection->executeStatement('UPDATE counterparty SET inn = ? WHERE id = ?', ['7700000088', $card->getId()]);
        $this->expectException(\DomainException::class);
        try {
            $this->assign($taxIds, (string) $this->member->getId(), $card->getId(), (string) $this->head->getId());
        } finally {
            self::assertSame('7700000088', $this->connection->fetchOne('SELECT inn FROM counterparty WHERE id = ?', [$card->getId()]));
        }
    }

    private function taxIds(): IntragroupTaxIds
    {
        return new IntragroupTaxIds($this->connection, self::getContainer()->get(CounterpartyFacade::class));
    }

    /** @return list<string> */
    private function members(): array
    {
        return [(string) $this->head->getId(), (string) $this->member->getId(), (string) $this->third->getId()];
    }

    private function assign(IntragroupTaxIds $taxIds, string $companyId, string $cardId, string $peerId): void
    {
        $container = self::getContainer();
        $action = new AssignIntragroupTaxIdAction(
            $this->em, new IntragroupGroupAccess($container->get(ConsolidationGroupFacadeInterface::class), $container->get(CompanyFacade::class)),
            $taxIds, $container->get(CounterpartyFacade::class),
        );
        $action((string) $this->head->getId(), (string) $this->owner->getId(), $companyId, $cardId, $peerId);
    }

    private function company(string $name, string $inn): Company
    {
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withName($name)->withOwner($this->owner)->build()->setInn($inn);
        $this->em->persist($company);

        return $company;
    }

    private function card(Company $company, string $name, ?string $inn = null): Counterparty
    {
        $cp = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($company)->withName($name)->withInn($inn)->build();
        $this->em->persist($cp);

        return $cp;
    }

    private function operation(Counterparty $card, string $date, bool $deleted = false, string $currency = 'RUB'): void
    {
        $company = $card->getCompany();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->withId(Uuid::uuid7()->toString())->withName('Счёт '.Uuid::uuid7())->withCurrency($currency)->build();
        $tx = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withMoneyAccount($account)->withCurrency($currency)->withOccurredAt(new \DateTimeImmutable($date))->build();
        $tx->setCounterparty($card);
        if ($deleted) {
            $tx->markDeleted(null);
        }
        $this->em->persist($account);
        $this->em->persist($tx);
    }
}
