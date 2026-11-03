<?php

declare(strict_types=1);

namespace App\Marketplace\Application\PlPreview;

use App\Marketplace\Application\Command\BuildMarketplacePlPreviewCommand;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewColumnMode;

/**
 * Фильтр предпросмотра ОПиУ из query-параметров — общий для экрана и выгрузки xlsx. Мусор в
 * параметрах даёт значение по умолчанию, а не ошибку: это ссылка, которую можно поправить руками.
 * Период по умолчанию — текущий месяц по сегодня, как у оперативного закрытия.
 */
final readonly class PlPreviewFilter
{
    /**
     * @param list<string>|null $tagIds null — все теги
     * @param list<string>|null $projectIds null — все проекты
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?MarketplaceType $marketplace,
        public ?array $tagIds,
        public bool $showEmptyRows,
        public PlPreviewColumnMode $mode = PlPreviewColumnMode::TAGS,
        public ?array $projectIds = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query, \DateTimeImmutable $today): self
    {
        $today = $today->setTime(0, 0);
        $marketplace = \is_string($query['marketplace'] ?? null) ? MarketplaceType::tryFrom($query['marketplace']) : null;

        $mode = \is_string($query['columns'] ?? null) ? PlPreviewColumnMode::tryFrom($query['columns']) : null;

        return new self(
            self::date($query['dateFrom'] ?? null) ?? $today->modify('first day of this month'),
            self::date($query['dateTo'] ?? null) ?? $today,
            $marketplace,
            self::ids($query, 'tags'),
            '1' === ($query['empty'] ?? null),
            $mode ?? PlPreviewColumnMode::TAGS,
            self::ids($query, 'projects'),
        );
    }

    public function toCommand(string $companyId): BuildMarketplacePlPreviewCommand
    {
        return new BuildMarketplacePlPreviewCommand(
            $companyId,
            $this->from,
            $this->to,
            $this->marketplace,
            $this->tagIds,
            $this->mode,
            $this->projectIds,
        );
    }

    /**
     * Параметры ссылки с тем же фильтром (пресеты периода, выгрузка).
     *
     * @return array<string, string|list<string>>
     */
    public function toQuery(): array
    {
        $query = ['dateFrom' => $this->from->format('Y-m-d'), 'dateTo' => $this->to->format('Y-m-d')];
        if (null !== $this->marketplace) {
            $query['marketplace'] = $this->marketplace->value;
        }
        if (null !== $this->tagIds) {
            $query['tags_set'] = '1';
            $query['tags'] = $this->tagIds;
        }
        if (PlPreviewColumnMode::TAGS !== $this->mode) {
            $query['columns'] = $this->mode->value;
        }
        if (null !== $this->projectIds) {
            $query['projects_set'] = '1';
            $query['projects'] = $this->projectIds;
        }
        if ($this->showEmptyRows) {
            $query['empty'] = '1';
        }

        return $query;
    }

    /**
     * Форма шлёт {name}_set=1: снятые все флажки — «без колонок», а не «все».
     *
     * @param array<string, mixed> $query
     *
     * @return list<string>|null
     */
    private static function ids(array $query, string $name): ?array
    {
        $ids = '1' === ($query[$name.'_set'] ?? null) ? [] : null;
        if (\is_array($query[$name] ?? null)) {
            $ids = array_values(array_unique(array_filter(
                $query[$name],
                static fn (mixed $id): bool => \is_string($id) && '' !== $id,
            )));
        }

        return $ids;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
