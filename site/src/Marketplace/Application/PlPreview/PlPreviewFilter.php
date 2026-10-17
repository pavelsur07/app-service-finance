<?php

declare(strict_types=1);

namespace App\Marketplace\Application\PlPreview;

use App\Marketplace\Application\Command\BuildMarketplacePlPreviewCommand;
use App\Marketplace\Enum\MarketplaceType;

/**
 * Фильтр предпросмотра ОПиУ из query-параметров — общий для экрана и выгрузки xlsx. Мусор в
 * параметрах даёт значение по умолчанию, а не ошибку: это ссылка, которую можно поправить руками.
 * Период по умолчанию — текущий месяц по сегодня, как у оперативного закрытия.
 */
final readonly class PlPreviewFilter
{
    /**
     * @param list<string>|null $tagIds null — все теги
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?MarketplaceType $marketplace,
        public ?array $tagIds,
        public bool $showEmptyRows,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query, \DateTimeImmutable $today): self
    {
        $today = $today->setTime(0, 0);
        $marketplace = \is_string($query['marketplace'] ?? null) ? MarketplaceType::tryFrom($query['marketplace']) : null;

        // Форма шлёт tags_set=1: снятые все флажки — «без колонок тегов», а не «все теги».
        $tagIds = '1' === ($query['tags_set'] ?? null) ? [] : null;
        if (\is_array($query['tags'] ?? null)) {
            $tagIds = array_values(array_unique(array_filter(
                $query['tags'],
                static fn (mixed $id): bool => \is_string($id) && '' !== $id,
            )));
        }

        return new self(
            self::date($query['dateFrom'] ?? null) ?? $today->modify('first day of this month'),
            self::date($query['dateTo'] ?? null) ?? $today,
            $marketplace,
            $tagIds,
            '1' === ($query['empty'] ?? null),
        );
    }

    public function toCommand(string $companyId): BuildMarketplacePlPreviewCommand
    {
        return new BuildMarketplacePlPreviewCommand($companyId, $this->from, $this->to, $this->marketplace, $this->tagIds);
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
        if ($this->showEmptyRows) {
            $query['empty'] = '1';
        }

        return $query;
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
