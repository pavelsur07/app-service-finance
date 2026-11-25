<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Repository\Transaction;

use App\Cash\Enum\Transaction\IntragroupDecisionType;
use App\Cash\Repository\Transaction\CashIntragroupDecisionRepository;
use App\Tests\Builders\Cash\CashIntragroupDecisionBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class CashIntragroupDecisionRepositoryTest extends IntegrationTestCase
{
    public function testLoadsAuditedDecisionsOnlyForTheirGroupEvenAfterLegsDisappear(): void
    {
        $company = Uuid::uuid7()->toString();
        $other = Uuid::uuid7()->toString();
        $decision = CashIntragroupDecisionBuilder::aDecision()->forCompany($company)->build();
        $foreign = CashIntragroupDecisionBuilder::aDecision()->forCompany($other)->build();
        $this->em->persist($decision);
        $this->em->persist($foreign);
        $this->em->flush();
        $this->em->clear();

        $repository = self::getContainer()->get(CashIntragroupDecisionRepository::class);
        $rows = $repository->forGroup($company);
        self::assertCount(1, $rows);
        $loaded = $rows[0];
        self::assertSame($loaded, $repository->findOneByIdAndCompanyId($decision->getId(), $company));
        self::assertNull($repository->findOneByIdAndCompanyId($decision->getId(), $other));
        self::assertSame($company, $loaded->getCompanyId());
        self::assertSame($decision->getType(), $loaded->getType());
        self::assertSame($decision->getOutflowTransactionId(), $loaded->getOutflowTransactionId());
        self::assertSame($decision->getInflowTransactionId(), $loaded->getInflowTransactionId());
        self::assertSame($decision->getCreatedBy(), $loaded->getCreatedBy());
        self::assertEquals($decision->getCreatedAt(), $loaded->getCreatedAt());
    }

    public function testUnpairBansOnlyOneCombinationAndAllowsTheSameLegInOtherDecisions(): void
    {
        $builder = CashIntragroupDecisionBuilder::aDecision();
        $out = Uuid::uuid7()->toString();
        $in = Uuid::uuid7()->toString();
        $other = Uuid::uuid7()->toString();
        $pair = $builder->withLegs($out, $in)->build();
        $this->em->persist($pair);
        foreach ([[$out, $in], [$out, $other], [$other, $in]] as [$outId, $inId]) {
            $this->em->persist($builder->withType(IntragroupDecisionType::UNPAIR)->withLegs($outId, $inId)->build());
        }
        $this->em->flush();

        self::assertCount(4, self::getContainer()->get(CashIntragroupDecisionRepository::class)->forGroup($pair->getCompanyId()));
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function conflictingDecisions(): iterable
    {
        yield 'повтор выплаты в PAIR' => ['PAIR', true, false];
        yield 'повтор поступления в PAIR' => ['PAIR', false, true];
        yield 'повтор той же комбинации в UNPAIR' => ['UNPAIR', true, true];
    }

    #[DataProvider('conflictingDecisions')]
    public function testDatabaseRejectsConflictingDecisions(string $type, bool $sameOut, bool $sameIn): void
    {
        $builder = CashIntragroupDecisionBuilder::aDecision()->withType(IntragroupDecisionType::from($type));
        $first = $builder->build();
        $this->em->persist($first);
        $this->em->flush();
        $next = $builder->withLegs($sameOut ? $first->getOutflowTransactionId() : Uuid::uuid7()->toString(), $sameIn ? $first->getInflowTransactionId() : Uuid::uuid7()->toString())->build();
        $this->em->persist($next);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testDecisionIdMustBeUuidV7(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CashIntragroupDecisionBuilder::aDecision()->withId(Uuid::uuid4()->toString())->build();
    }
}
