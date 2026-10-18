<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\DTO\OzonAccrualDetailsImportResult;
use App\Ingestion\Application\DTO\OzonAccrualDetailsRow;
use App\Ingestion\Application\DTO\OzonAccrualDetailsTypeResult;
use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Entity\OzonAccrualTypeGroup;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\OzonAccrualTypeGroupStatus;
use App\Ingestion\Infrastructure\Query\OzonAccrualByDayTypeLookupQuery;
use App\Ingestion\Infrastructure\Query\OzonAccrualTypeIndex;
use App\Ingestion\Repository\CategoryGroupRepository;
use App\Ingestion\Repository\OzonAccrualTypeGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Выводит группы Ozon по типам начислений из отчёта кабинета «Детализация
 * начислений».
 *
 * В отчёте нет `type_id`, а ID начисления не совпадает с `accrual_id` by-day.
 * Поэтому каждая строка ищется в by-day той же компании по дате, SKU и модулю
 * суммы; однозначные совпадения голосуют за `type_id` своей пары «Группа услуг /
 * Тип начисления». Пара подтверждена, если у победителя ≥ 80% однозначных голосов,
 * их не меньше двух и не меньше `min(3, строк пары)`.
 *
 * Решение принимается один раз на `type_id`, по всем его парам: одна группа у
 * подтверждённых пар — тип подтверждён (слабые пары его не трогают), разные —
 * конфликт. Таблица общая для всех компаний, поэтому прежнее подтверждение не
 * перезаписывается ни конфликтом, ни другой группой: другая группа записывается
 * как спор для админа.
 */
final readonly class ImportOzonAccrualDetailsAction
{
    private const MIN_SHARE = 0.8;
    private const MIN_VOTES_ABSOLUTE = 2;
    private const MIN_VOTES = 3;

    public function __construct(
        private OzonAccrualByDayTypeLookupQuery $lookupQuery,
        private CategoryGroupRepository $groupRepository,
        private OzonAccrualTypeGroupRepository $typeGroupRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<OzonAccrualDetailsRow> $rows
     */
    public function __invoke(string $companyId, array $rows, bool $dryRun = false): OzonAccrualDetailsImportResult
    {
        Assert::uuid($companyId);
        if ([] === $rows) {
            return new OzonAccrualDetailsImportResult(0, null, null, [], $dryRun);
        }

        $dates = array_map(static fn (OzonAccrualDetailsRow $row): string => $row->date, $rows);
        $periodFrom = new \DateTimeImmutable(min($dates));
        $periodTo = new \DateTimeImmutable(max($dates));
        $index = $this->lookupQuery->build(
            $companyId,
            $periodFrom,
            $periodTo,
            array_map(static fn (OzonAccrualDetailsRow $row): int => $row->amountMinor, $rows),
        );

        $pairs = $this->pairs($rows, $index);
        $groupCodes = $this->groupCodesByLabel();
        $results = [];
        /** @var array<int, list<array{group: string, type: string, rows: int, votes: int, groupCode: string, confirmed: bool}>> $byTypeId */
        $byTypeId = [];

        foreach ($pairs as $pair) {
            if ([] === $pair['votes']) {
                $results[] = new OzonAccrualDetailsTypeResult($pair['type'], $pair['group'], null, null, 0, $pair['rows'], OzonAccrualDetailsTypeResult::UNMATCHED);
                continue;
            }

            arsort($pair['votes']);
            $typeId = (int) array_key_first($pair['votes']);
            $votes = $pair['votes'][$typeId];
            $groupCode = $groupCodes[self::normalizeLabel($pair['group'])] ?? null;
            if (null === $groupCode) {
                $results[] = new OzonAccrualDetailsTypeResult($pair['type'], $pair['group'], $typeId, null, $votes, $pair['rows'], OzonAccrualDetailsTypeResult::UNKNOWN_GROUP);
                continue;
            }

            $byTypeId[$typeId][] = [
                'group' => $pair['group'],
                'type' => $pair['type'],
                'rows' => $pair['rows'],
                'votes' => $votes,
                'groupCode' => $groupCode,
                'confirmed' => $votes / array_sum($pair['votes']) >= self::MIN_SHARE
                    && $votes >= self::MIN_VOTES_ABSOLUTE
                    && $votes >= min(self::MIN_VOTES, $pair['rows']),
            ];
        }

        $existing = $this->typeGroupRepository->findAllIndexedByTypeId();
        foreach ($byTypeId as $typeId => $typePairs) {
            array_push($results, ...$this->decide($companyId, $typeId, $typePairs, $existing[$typeId] ?? null, $periodFrom, $periodTo, $dryRun));
        }

        if (!$dryRun) {
            $this->entityManager->flush();
            $this->logger->info('Ozon accrual details report imported.', [
                'companyId' => $companyId,
                'rows' => \count($rows),
                'periodFrom' => $periodFrom->format('Y-m-d'),
                'periodTo' => $periodTo->format('Y-m-d'),
                'confirmed' => \count(array_filter($results, static fn (OzonAccrualDetailsTypeResult $r): bool => OzonAccrualDetailsTypeResult::CONFIRMED === $r->outcome)),
                'disputed' => \count(array_filter($results, static fn (OzonAccrualDetailsTypeResult $r): bool => OzonAccrualDetailsTypeResult::DISPUTED === $r->outcome)),
            ]);
        }

        return new OzonAccrualDetailsImportResult(\count($rows), $periodFrom->format('Y-m-d'), $periodTo->format('Y-m-d'), $results, $dryRun);
    }

    /**
     * @param list<array{group: string, type: string, rows: int, votes: int, groupCode: string, confirmed: bool}> $typePairs
     *
     * @return list<OzonAccrualDetailsTypeResult>
     */
    private function decide(
        string $companyId,
        int $typeId,
        array $typePairs,
        ?OzonAccrualTypeGroup $current,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        bool $dryRun,
    ): array {
        $confirmedPairs = array_values(array_filter($typePairs, static fn (array $pair): bool => $pair['confirmed']));
        $confirmedGroups = array_values(array_unique(array_column($confirmedPairs, 'groupCode')));
        $currentGroup = $current?->confirmedGroupCode();

        if (1 === \count($confirmedGroups)) {
            $groupCode = $confirmedGroups[0];
            $votes = array_sum(array_column($confirmedPairs, 'votes'));
            $rowsTotal = array_sum(array_column($confirmedPairs, 'rows'));
            $lead = $confirmedPairs[0];

            if (null !== $currentGroup && $currentGroup !== $groupCode) {
                if (!$dryRun) {
                    $current->recordDispute($groupCode, $votes, $companyId);
                }
                $this->logger->warning('Ozon accrual type group disputed by cabinet report; confirmed group kept.', [
                    'companyId' => $companyId,
                    'typeId' => $typeId,
                    'confirmedGroupCode' => $currentGroup,
                    'reportedGroupCode' => $groupCode,
                ]);
                $outcome = OzonAccrualDetailsTypeResult::DISPUTED;
            } else {
                if (!$dryRun) {
                    if (null === $current) {
                        $this->entityManager->persist(new OzonAccrualTypeGroup($typeId, $groupCode, OzonAccrualTypeGroupStatus::CONFIRMED, $lead['type'], $lead['group'], $votes, $rowsTotal, $companyId, $periodFrom, $periodTo));
                    } else {
                        $current->replaceEvidence($groupCode, OzonAccrualTypeGroupStatus::CONFIRMED, $lead['type'], $lead['group'], $votes, $rowsTotal, $companyId, $periodFrom, $periodTo);
                    }
                }
                $outcome = OzonAccrualDetailsTypeResult::CONFIRMED;
            }

            return array_map(
                static fn (array $pair): OzonAccrualDetailsTypeResult => new OzonAccrualDetailsTypeResult(
                    $pair['type'],
                    $pair['group'],
                    $typeId,
                    $pair['groupCode'],
                    $pair['votes'],
                    $pair['rows'],
                    $pair['confirmed'] ? $outcome : OzonAccrualDetailsTypeResult::WEAK,
                ),
                $typePairs,
            );
        }

        // Нет подтверждённых пар или они разошлись по группам.
        $outcome = null !== $currentGroup ? OzonAccrualDetailsTypeResult::KEPT : OzonAccrualDetailsTypeResult::CONFLICT;
        if (!$dryRun && null === $current) {
            usort($typePairs, static fn (array $a, array $b): int => $b['votes'] <=> $a['votes']);
            $lead = $typePairs[0];
            $this->entityManager->persist(new OzonAccrualTypeGroup($typeId, $lead['groupCode'], OzonAccrualTypeGroupStatus::CONFLICT, $lead['type'], $lead['group'], $lead['votes'], $lead['rows'], $companyId, $periodFrom, $periodTo));
        }

        return array_map(
            static fn (array $pair): OzonAccrualDetailsTypeResult => new OzonAccrualDetailsTypeResult($pair['type'], $pair['group'], $typeId, $pair['groupCode'], $pair['votes'], $pair['rows'], $outcome),
            $typePairs,
        );
    }

    /**
     * @param list<OzonAccrualDetailsRow> $rows
     *
     * @return list<array{group: string, type: string, rows: int, votes: array<int, int>}>
     */
    private function pairs(array $rows, OzonAccrualTypeIndex $index): array
    {
        $pairs = [];
        foreach ($rows as $row) {
            $key = $row->groupLabel."\u{0000}".$row->typeName;
            $pairs[$key] ??= ['group' => $row->groupLabel, 'type' => $row->typeName, 'rows' => 0, 'votes' => []];
            ++$pairs[$key]['rows'];

            $candidates = $index->typeIds($row->date, $row->sku, $row->amountMinor);
            if (1 === \count($candidates)) {
                $pairs[$key]['votes'][$candidates[0]] = ($pairs[$key]['votes'][$candidates[0]] ?? 0) + 1;
            }
        }

        return array_values($pairs);
    }

    /**
     * @return array<string, string> нормализованная метка группы (наша или как в отчёте Ozon) => код
     */
    private function groupCodesByLabel(): array
    {
        $codes = [];
        foreach ($this->groupRepository->findBySourceIndexedByCode(IngestSource::OZON) as $code => $group) {
            $codes[self::normalizeLabel($group->getLabel())] = $code;
            if (null !== $group->getOzonLabel()) {
                $codes[self::normalizeLabel($group->getOzonLabel())] = $code;
            }
        }

        return $codes;
    }

    private static function normalizeLabel(string $label): string
    {
        return OzonAccrualCategoryTaxonomyResolver::normalizeName($label) ?? '';
    }
}
