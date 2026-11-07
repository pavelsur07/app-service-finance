<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Settlement\Domain\SettlementMutationPolicy;
use App\Settlement\Entity\SettlementBook;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ConfigureSettlementBookAction
{
    public function __construct(private EntityManagerInterface $em, private SettlementMutationPolicy $policy)
    {
    }

    public function __invoke(string $companyId, string $actorUserId, \DateTimeImmutable $startDate): void
    {
        $this->em->wrapInTransaction(function () use ($companyId, $actorUserId, $startDate): void {
            $this->policy->requireWrite($companyId, $actorUserId);
            // An advisory lock serializes creation before the company has a book row.
            $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'settlement:'.$companyId]);
            $existing = $this->em->getRepository(SettlementBook::class)->findOneBy(['companyId' => $companyId]);
            if (null !== $existing) {
                if ($existing->getStartDate()->format('Y-m-d') === $startDate->format('Y-m-d')) {
                    return;
                }
                throw new \DomainException('Дата начала уже настроена.');
            }
            $book = new SettlementBook($companyId, $startDate);
            $this->policy->assertDate($companyId, $book, $startDate);
            $this->em->persist($book);
        });
    }
}
