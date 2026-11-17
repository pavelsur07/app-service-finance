<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\AutoRule;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CashAutoRuleConfirmation
{
    public function __construct(#[Autowire('%kernel.secret%')] private string $secret)
    {
    }

    /** @param array<string, int> $activeVersions */
    public function issue(CashTransactionAutoRule $draft, string $actorId, int $originalRevision, array $activeVersions): string
    {
        $snapshot = $originalRevision.'.'.self::versionsHash($activeVersions);

        return $snapshot.'.'.$this->signature($draft, $actorId, $snapshot);
    }

    /** @return array{int, string} */
    public function verify(CashTransactionAutoRule $draft, string $actorId, bool $isNew, string $token): array
    {
        if (1 !== preg_match('/^(0|[1-9][0-9]*)\.([a-f0-9]{64})\.([a-f0-9]{64})$/D', $token, $parts)
            || $isNew !== ('0' === $parts[1])
            || !hash_equals($this->signature($draft, $actorId, $parts[1].'.'.$parts[2]), $parts[3])
        ) {
            throw new \DomainException('Форма изменилась или проверка не найдена. Повторите проверку.');
        }

        return [(int) $parts[1], $parts[2]];
    }

    /** @param array<string, int> $activeVersions */
    public static function versionsHash(array $activeVersions): string
    {
        ksort($activeVersions, \SORT_STRING);

        return hash('sha256', json_encode($activeVersions, \JSON_THROW_ON_ERROR));
    }

    private function signature(CashTransactionAutoRule $draft, string $actorId, string $snapshot): string
    {
        return hash_hmac('sha256', implode('|', [
            'cash-rule-approval-v1', $draft->getCompany()->getId(), $actorId, $draft->getId(),
            $draft->definitionHash(), $snapshot,
        ]), $this->secret);
    }
}
