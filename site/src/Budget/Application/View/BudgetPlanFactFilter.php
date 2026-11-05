<?php

declare(strict_types=1);

namespace App\Budget\Application\View;

/**
 * Фильтр план–факта «проекты × ЦФО» (мультивыбор, BUDGET-SPEC §6). null — измерение не
 * фильтруется (вся компания). Проекты для расчёта уже развёрнуты с поддеревом; `includeUnassigned`
 * — среди выбранных ЦФО системный «Общий», факт без ЦФО считается его (вариант A).
 */
final readonly class BudgetPlanFactFilter
{
    /**
     * @param list<array{id: string, name: string, level: int, selected: bool}> $projectOptions
     * @param list<array{id: string, name: string, system: bool, selected: bool}> $centerOptions
     * @param list<string>|null $selectedProjectIds выбранные (без развёртки), null — все
     * @param list<string>|null $projectIds выбранные с дочерними, null — все
     * @param list<string>|null $centerIds null — все
     */
    public function __construct(
        public array $projectOptions,
        public array $centerOptions,
        public ?array $selectedProjectIds,
        public ?array $projectIds,
        public ?array $centerIds,
        public bool $includeUnassigned,
    ) {
    }

    public function isAll(): bool
    {
        return null === $this->projectIds && null === $this->centerIds;
    }

    /**
     * Отчёт ОПиУ при фильтре по ЦФО не показывает операции без ЦФО, а план–факт считает их
     * «Общему»: с «Общим» среди выбранных ЦФО суммы отчёта не совпадут.
     */
    public function reportLinkable(): bool
    {
        return null === $this->centerIds || !$this->includeUnassigned;
    }

    /**
     * Параметры адреса страницы: только заданные измерения.
     *
     * @return array<string, list<string>>
     */
    public function query(): array
    {
        $selectedCenters = null === $this->centerIds ? null : array_values(array_map(
            static fn (array $center): string => $center['id'],
            array_filter($this->centerOptions, static fn (array $center): bool => $center['selected']),
        ));

        return array_filter(['projects' => $this->selectedProjectIds, 'centers' => $selectedCenters], static fn (?array $ids): bool => null !== $ids);
    }

    /**
     * Те же фильтры для отчёта ОПиУ (проекты — выбранные: отчёт сам берёт дочерние).
     *
     * @return array<string, int|list<string>>
     */
    public function reportQuery(): array
    {
        $query = [];
        if (null !== $this->selectedProjectIds) {
            $query['projectFiltersPresent'] = 1;
            $query['projectDirectionIds'] = $this->selectedProjectIds;
        }
        if (null !== $this->centerIds) {
            $query['responsibilityCenterFiltersPresent'] = 1;
            $query['responsibilityCenterIds'] = $this->centerIds;
        }

        return $query;
    }

    /** Подпись пилюли: «Все», название единственного или «N из M». */
    public function projectLabel(): string
    {
        return self::label($this->projectOptions, null === $this->selectedProjectIds);
    }

    public function centerLabel(): string
    {
        return self::label($this->centerOptions, null === $this->centerIds);
    }

    /**
     * @param list<array{id: string, name: string, selected: bool}> $options
     */
    private static function label(array $options, bool $all): string
    {
        if ($all) {
            return 'Все';
        }
        $selected = array_values(array_filter($options, static fn (array $option): bool => $option['selected']));

        return 1 === \count($selected) ? $selected[0]['name'] : sprintf('%d из %d', \count($selected), \count($options));
    }
}
