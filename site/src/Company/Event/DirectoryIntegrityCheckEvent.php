<?php

declare(strict_types=1);

namespace App\Company\Event;

/**
 * Ночная проверка справочников (`app:directories:integrity-check`), только чтение. Модули со
 * справочниками компании (ОПиУ, ДДС, Баланс) подписываются и сообщают соседние статьи с
 * одинаковым названием — дубль, который вводит в заблуждение в отчётах; справочные наблюдения
 * (`note()`) на результат проверки не влияют. Company о модулях не знает.
 */
final class DirectoryIntegrityCheckEvent
{
    /** @var list<DirectoryDuplicate> */
    private array $duplicates = [];

    /** @var list<array{string, string, string}> справочник, компания, текст */
    private array $notes = [];

    /**
     * @param list<string> $companyIds компании, которые проверяются
     */
    public function __construct(public readonly array $companyIds)
    {
    }

    public function duplicate(DirectoryDuplicate $duplicate): void
    {
        $this->duplicates[] = $duplicate;
    }

    public function note(string $directory, string $companyId, string $text): void
    {
        $this->notes[] = [$directory, $companyId, $text];
    }

    /**
     * @return list<DirectoryDuplicate> по справочнику, компании, названию: порядок подписчиков не гарантирован
     */
    public function duplicates(): array
    {
        $duplicates = $this->duplicates;
        usort($duplicates, static fn (DirectoryDuplicate $a, DirectoryDuplicate $b): int => [$a->directory, $a->companyId, $a->name] <=> [$b->directory, $b->companyId, $b->name]);

        return $duplicates;
    }

    /**
     * @return list<array{string, string, string}>
     */
    public function notes(): array
    {
        $notes = $this->notes;
        sort($notes);

        return $notes;
    }
}
