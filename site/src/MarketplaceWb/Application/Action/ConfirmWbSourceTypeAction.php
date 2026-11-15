<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Application\Action;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceWb\Exception\WbSourceTypeNotConfirmableException;
use App\MarketplaceWb\Infrastructure\Query\WbTranslationStore;

/**
 * Решение администратора платформы по коду WB: вид и название. Один оператор UPDATE —
 * своя транзакция не нужна. Дни из карантина с этим кодом следующий прогон переводчика
 * переводит сам (`daysWithDecidedQuarantine`).
 */
final readonly class ConfirmWbSourceTypeAction
{
    public function __construct(
        private WbTranslationStore $store,
    ) {
    }

    public function __invoke(string $sourceType, string $kind, string $name, string $userId): void
    {
        $operationKind = OperationKind::tryFrom($kind) ?? throw new WbSourceTypeNotConfirmableException('Выберите вид операции из списка.');
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 255) {
            throw new WbSourceTypeNotConfirmableException('Название — от 1 до 255 символов.');
        }
        if (!$this->store->confirm($sourceType, $operationKind, $name, $userId)) {
            throw new WbSourceTypeNotConfirmableException(sprintf('Код %s уже решён или не найден.', $sourceType));
        }
    }
}
