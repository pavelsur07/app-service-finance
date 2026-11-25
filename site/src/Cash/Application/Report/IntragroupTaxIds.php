<?php

declare(strict_types=1);

namespace App\Cash\Application\Report;

use App\Cash\Application\DTO\IntragroupTaxIdCandidate;
use App\Company\Facade\CounterpartyFacade;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * @phpstan-type Profile array{inn: string, core: string, legalFormHint: ?string}
 * @phpstan-type Card array{id: string, company_id: string, name: string, name_core: string, legal_form_hint: ?string}
 */
final readonly class IntragroupTaxIds
{
    public function __construct(
        private Connection $connection,
        private CounterpartyFacade $counterparties,
    ) {
    }

    /**
     * @param list<string> $companyIds компании группы после проверки доступа
     *
     * @return list<IntragroupTaxIdCandidate>
     */
    public function candidates(array $companyIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $profiles = $this->profiles($companyIds);
        $lists = ['companies' => $companyIds, 'cores' => array_column($profiles, 'core'), 'inns' => array_column($profiles, 'inn')];
        /** @var list<array{id: string, company_id: string, name: string, name_core: string, legal_form_hint: ?string, operations: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT cp.id, cp.company_id, cp.name, cp.name_core, cp.legal_form_hint, count(t.id) AS operations
                FROM counterparty cp
                LEFT JOIN cash_transaction t ON t.counterparty_id = cp.id AND t.company_id = cp.company_id
                  AND t.deleted_at IS NULL AND t.currency = 'RUB' AND t.occurred_at BETWEEN :from AND :to
                WHERE cp.company_id IN (:companies) AND NULLIF(trim(cp.inn), '') IS NULL
                  AND (cp.name_core IN (:cores) OR substring(cp.name FROM '^([0-9]{10}([0-9]{2})?),') IN (:inns))
                GROUP BY cp.id
                ORDER BY cp.company_id, cp.name, cp.id
                SQL,
            $lists + ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            array_fill_keys(array_keys($lists), ArrayParameterType::STRING),
        );
        $candidates = [];
        foreach ($rows as $card) {
            $peerId = $this->peerOf($card, $profiles);
            if (null !== $peerId) {
                $candidates[] = new IntragroupTaxIdCandidate($card['company_id'], $card['id'], $card['name'], $peerId, $profiles[$peerId]['inn'], (int) $card['operations']);
            }
        }

        return $candidates;
    }

    /**
     * Повторно определяет встречную компанию по свежим реквизитам. Заполненная карточка
     * тоже проверяется: повтор POST с тем же ИНН допустим.
     *
     * @param list<string> $members
     *
     * @return array{name: string, inn: string}|null
     */
    public function forAssignment(array $members, string $companyId, string $cardId, string $peerCompanyId): ?array
    {
        $profiles = $this->profiles($members);
        if (!isset($profiles[$companyId], $profiles[$peerCompanyId])) {
            return null;
        }
        /** @var Card|false $card */
        $card = $this->connection->fetchAssociative(
            'SELECT id, company_id, name, name_core, legal_form_hint FROM counterparty WHERE company_id = ? AND id = ?',
            [$companyId, $cardId],
        );
        if (false === $card || $this->peerOf($card, $profiles) !== $peerCompanyId) {
            return null;
        }

        return ['name' => $card['name'], 'inn' => $profiles[$peerCompanyId]['inn']];
    }

    /** @param list<string> $companyIds
     * @return array<string, Profile>
     */
    private function profiles(array $companyIds): array
    {
        /** @var list<array{id: string, name: ?string, inn: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, inn FROM companies WHERE id IN (:companies)',
            ['companies' => $companyIds], ['companies' => ArrayParameterType::STRING],
        );
        $profiles = [];
        foreach ($rows as $company) {
            $name = trim((string) $company['name']);
            $parts = '' === $name ? ['core' => '', 'legalFormHint' => null] : $this->counterparties->normalizedNameParts($name);
            $profiles[$company['id']] = ['inn' => trim((string) $company['inn'])] + $parts;
        }

        return $profiles;
    }

    /**
     * @param Card $card
     * @param array<string, Profile> $profiles
     */
    private function peerOf(array $card, array $profiles): ?string
    {
        $hasPrefix = 1 === preg_match('/^([0-9]{10}(?:[0-9]{2})?),\s*/u', $card['name'], $prefix);
        $matches = [];
        foreach ($profiles as $peerId => $peer) {
            $sameName = $peer['core'] === $card['name_core'] && (null === $peer['legalFormHint'] || null === $card['legal_form_hint'] || $peer['legalFormHint'] === $card['legal_form_hint']);
            if ($hasPrefix ? $peer['inn'] === $prefix[1] : $sameName) {
                $matches[] = $peerId;
            }
        }
        if (1 !== \count($matches) || $matches[0] === $card['company_id']) {
            return null;
        }
        $inn = $profiles[$matches[0]]['inn'];
        if (1 !== preg_match('/^[0-9]{10}([0-9]{2})?$/D', $inn) || 1 !== \count(array_keys(array_column($profiles, 'inn'), $inn, true))) {
            return null;
        }

        return $matches[0];
    }
}
