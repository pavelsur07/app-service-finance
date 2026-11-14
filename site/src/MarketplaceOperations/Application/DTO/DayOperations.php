<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Application\DTO;

use Webmozart\Assert\Assert;

/**
 * Все операции дня одного кабинета — единица записи канона. Пустой список = в этот день
 * операций нет (прежние удаляются).
 */
final readonly class DayOperations
{
    /**
     * @param list<OperationInput> $operations
     */
    public function __construct(
        public string $companyId,
        /** Канал без деталей: `ozon`, `wb`, позже `store_*`. */
        public string $channel,
        public string $connectionRef,
        public \DateTimeImmutable $date,
        /** Версия правил переводчика, которой получены операции. */
        public string $rulesVersion,
        public array $operations,
    ) {
        Assert::uuid($companyId);
        Assert::regex($channel, '/^[a-z][a-z0-9_]{0,31}$/');
        Assert::stringNotEmpty($connectionRef);
        Assert::maxLength($connectionRef, 64);
        Assert::stringNotEmpty($rulesVersion);
        Assert::maxLength($rulesVersion, 32);
    }
}
