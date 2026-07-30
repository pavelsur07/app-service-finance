<?php

declare(strict_types=1);

namespace App\Company\Domain\ValueObject;

/**
 * Название контрагента в трёх ролях: отображение, разобранная ОПФ, поисковый ключ.
 *
 * Создать можно только через CounterpartyNameNormalizer::normalize(): конструктор
 * приватный, поэтому в Entity не попадает ненормализованное значение.
 */
final readonly class CounterpartyName
{
    private function __construct(
        public string $display,
        public ?string $legalFormHint,
        public string $core,
    ) {
    }

    /**
     * Это же значение, но с точностью до лишних пробелов.
     *
     * Нужно backfill'у: у исторической записи название может содержать двойные
     * пробелы, и пересчёт производных полей не должен считать это переименованием.
     */
    public function isDisplayOf(string $rawName): bool
    {
        return $this->display === self::collapseWhitespace($rawName);
    }

    /**
     * Единственное определение правила «схлопнуть пробелы»: им пользуются и
     * нормализатор, и сравнение выше.
     */
    public static function collapseWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @internal Только для CounterpartyNameNormalizer — единственной точки нормализации.
     *           Прямой вызов из прикладного кода запрещён (см. CLAUDE.md, ТЗ §3.1).
     */
    public static function fromNormalizedParts(string $display, ?string $legalFormHint, string $core): self
    {
        if ('' === $display) {
            throw new \InvalidArgumentException('Название контрагента не может быть пустым.');
        }

        if ('' === $core) {
            throw new \InvalidArgumentException('Нормализованное название не может быть пустым.');
        }

        return new self($display, $legalFormHint, $core);
    }
}
