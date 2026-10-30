<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Exception\CashCategorySuggestionNotFoundException;
use App\Cash\Repository\Transaction\CashCategorySuggestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * «Отклонить» ИИ-подсказку: операция остаётся без статьи, решение — метка точности.
 */
final class DismissCashCategorySuggestionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashCategorySuggestionRepository $suggestions,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $companyId, string $suggestionId, string $userId): void
    {
        Assert::uuid($companyId);
        Assert::uuid($userId);

        $suggestion = $this->suggestions->findOneForCompany($companyId, $suggestionId)
            ?? throw CashCategorySuggestionNotFoundException::forId($suggestionId);
        $suggestion->dismiss($userId, $this->clock->now());
        $this->entityManager->flush();
    }
}
