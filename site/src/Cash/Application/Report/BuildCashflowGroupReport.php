<?php

declare(strict_types=1);

namespace App\Cash\Application\Report;

use App\Cash\Application\DTO\CashflowGroupReport;
use App\Cash\Application\DTO\CashflowGroupReportLine;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Infrastructure\Query\CashflowGroupReportQuery;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\ConsolidationGroupFacadeInterface;

/**
 * Консолидированный отчёт ДДС группы компании за период, только чтение. Строки — статьи эталона
 * (дерево головной компании): сумма статьи компании сворачивается к ближайшей статье с кодом
 * (`group_code`); операция прямо на системной статье — в её секцию; статья без кода до системной
 * или код, которого нет в эталоне, — «Не сопоставлено». Внутригрупповая операция — ИНН контрагента
 * равен ИНН другой компании группы: показывается отдельно и вычитается из итога. Только RUB.
 */
final readonly class BuildCashflowGroupReport
{
    public const CURRENCY = 'RUB';
    public const UNMATCHED = 'unmatched';
    public const TOTAL = 'total';

    public function __construct(
        private ConsolidationGroupFacadeInterface $groups,
        private CompanyFacade $companyFacade,
        private CashflowCategoryRepository $categories,
        private CashflowGroupReportQuery $query,
    ) {
    }

    /** null — компания не входит в группу консолидации. */
    public function __invoke(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): ?CashflowGroupReport
    {
        $head = $this->groups->headOf($companyId);
        if (null === $head) {
            return null;
        }

        /** @var array<string, Company> $companies */
        $companies = [];
        $names = [];
        /** @var array<string, list<string>> $innOwners ИНН => компании группы с этим ИНН */
        $innOwners = [];
        $withoutInn = [];
        foreach ($this->groups->members($head) as $id) {
            $company = $this->companyFacade->findById($id) ?? throw new \DomainException(sprintf('Компания группы %s не найдена.', $id));
            $companies[$id] = $company;
            $names[$id] = (string) $company->getName();
            $inn = trim((string) $company->getInn());
            if ('' === $inn) {
                $withoutInn[] = $names[$id];
            } else {
                // ИНН не уникален между компаниями: храним всех владельцев.
                $innOwners[$inn][] = $id;
            }
        }
        $zero = array_fill_keys(array_keys($companies), '0.00');

        /** @var array<string, string> $keyOf categoryId => ключ строки отчёта */
        $keyOf = [];
        foreach ($companies as $company) {
            foreach ($this->categories->findTreeByCompany($company) as $category) {
                $keyOf[(string) $category->getId()] = self::key($category);
            }
        }

        /** @var array<string, array<string, string>> $own ключ => companyId => сумма (без подстатей) */
        $own = [];
        /** @var array<string, string> $ownIntragroup */
        $ownIntragroup = [];
        $otherCurrencies = [];
        foreach (($this->query)(array_keys($companies), $from, $to) as $amount) {
            if (self::CURRENCY !== $amount->currency) {
                $otherCurrencies[$amount->currency] ??= $zero;
                $otherCurrencies[$amount->currency][$amount->companyId] = bcadd($otherCurrencies[$amount->currency][$amount->companyId], $amount->amount, 2);
                continue;
            }
            $key = $keyOf[$amount->categoryId] ?? self::UNMATCHED;
            $own[$key] ??= $zero;
            $own[$key][$amount->companyId] = bcadd($own[$key][$amount->companyId], $amount->amount, 2);
            $owners = null === $amount->counterpartyInn ? [] : ($innOwners[$amount->counterpartyInn] ?? []);
            if ([] !== array_diff($owners, [$amount->companyId])) {
                $ownIntragroup[$key] = bcadd($ownIntragroup[$key] ?? '0.00', $amount->amount, 2);
            }
        }

        $lines = [];
        $shown = [];
        $grand = $zero;
        $grandIntragroup = '0.00';
        $roots = array_values(array_filter($this->categories->findTreeByCompany($companies[$head]), static fn (CashflowCategory $c): bool => null === $c->getParent()));
        usort($roots, static fn (CashflowCategory $a, CashflowCategory $b): int => [$a->getSort(), $a->getName()] <=> [$b->getSort(), $b->getName()]);
        foreach ($roots as $root) {
            [$sum, $intragroup] = $this->visit($root, 1, $lines, $shown, $own, $ownIntragroup, $zero);
            $grand = self::add($grand, $sum);
            $grandIntragroup = bcadd($grandIntragroup, $intragroup, 2);
        }

        $unmatched = $zero;
        $unmatchedIntragroup = '0.00';
        foreach ($own as $key => $amounts) {
            if (!isset($shown[$key])) {
                $unmatched = self::add($unmatched, $amounts);
                $unmatchedIntragroup = bcadd($unmatchedIntragroup, $ownIntragroup[$key] ?? '0.00', 2);
            }
        }
        $grand = self::add($grand, $unmatched);
        $grandIntragroup = bcadd($grandIntragroup, $unmatchedIntragroup, 2);

        return new CashflowGroupReport(
            $head,
            $from,
            $to,
            $names,
            array_values($lines),
            self::line(self::UNMATCHED, 'Не сопоставлено', 1, false, $unmatched, $unmatchedIntragroup),
            self::line(self::TOTAL, 'Итого', 1, false, $grand, $grandIntragroup),
            $otherCurrencies,
            $withoutInn,
        );
    }

    /**
     * Строка статьи головной и её подстатей (пре-ордер); статья головной без кода строки не
     * получает — её подстатьи поднимаются на её уровень.
     *
     * @param array<int, CashflowGroupReportLine> $lines
     * @param array<string, true> $shown
     * @param array<string, array<string, string>> $own
     * @param array<string, string> $ownIntragroup
     * @param array<string, string> $zero
     *
     * @return array{array<string, string>, string} суммы с подстатьями и внутригрупповые
     */
    private function visit(CashflowCategory $category, int $level, array &$lines, array &$shown, array $own, array $ownIntragroup, array $zero): array
    {
        // Строка — только по собственному коду или системной статье: ключ предка не наследуется,
        // иначе подстатья головной без кода повторно взяла бы сумму родителя.
        $key = $category->getGroupCode() ?? ($category->isSystem() && null !== $category->getSystemCode() ? 'sys:'.$category->getSystemCode() : null);
        $hasLine = null !== $key;
        $position = \count($lines);
        if ($hasLine) {
            $shown[$key] = true;
            $lines[] = self::line($key, '', $level, false, $zero, '0.00');
        }

        $sum = $hasLine ? ($own[$key] ?? $zero) : $zero;
        $intragroup = $hasLine ? ($ownIntragroup[$key] ?? '0.00') : '0.00';
        foreach ($category->getChildren() as $child) {
            [$childSum, $childIntragroup] = $this->visit($child, $hasLine ? $level + 1 : $level, $lines, $shown, $own, $ownIntragroup, $zero);
            $sum = self::add($sum, $childSum);
            $intragroup = bcadd($intragroup, $childIntragroup, 2);
        }

        if ($hasLine) {
            $lines[$position] = self::line($key, $category->getName(), $level, $category->isSystem(), $sum, $intragroup);
        }

        return [$sum, $intragroup];
    }

    /** Ключ строки для статьи компании. */
    private static function key(CashflowCategory $category): string
    {
        for ($node = $category; null !== $node; $node = $node->getParent()) {
            if (null !== $node->getGroupCode()) {
                return $node->getGroupCode();
            }
            if ($node->isSystem() && null !== $node->getSystemCode()) {
                // Прямо на системной статье — её секция; статья без кода под ней — не сопоставлена.
                return $node === $category ? 'sys:'.$node->getSystemCode() : self::UNMATCHED;
            }
        }

        return self::UNMATCHED;
    }

    /** @param array<string, string> $amounts */
    private static function line(string $key, string $name, int $level, bool $section, array $amounts, string $intragroup): CashflowGroupReportLine
    {
        $sum = '0.00';
        foreach ($amounts as $amount) {
            $sum = bcadd($sum, $amount, 2);
        }

        return new CashflowGroupReportLine($key, $name, $level, $section, $amounts, $intragroup, bcsub($sum, $intragroup, 2));
    }

    /**
     * @param array<string, string> $a
     * @param array<string, string> $b
     *
     * @return array<string, string>
     */
    private static function add(array $a, array $b): array
    {
        foreach ($b as $id => $amount) {
            $a[$id] = bcadd($a[$id] ?? '0.00', $amount, 2);
        }

        return $a;
    }
}
