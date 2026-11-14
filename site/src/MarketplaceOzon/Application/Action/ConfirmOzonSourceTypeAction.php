<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Application\Action;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceOzon\Exception\OzonSourceTypeNotConfirmableException;
use App\MarketplaceOzon\Infrastructure\Query\OzonTranslationStore;

/**
 * Решение администратора платформы по коду Ozon: вид и название. Один оператор UPDATE —
 * своя транзакция не нужна. Дни из карантина с этим кодом следующий прогон переводчика
 * переводит сам (`daysWithDecidedQuarantine`).
 */
final readonly class ConfirmOzonSourceTypeAction
{
    public function __construct(
        private OzonTranslationStore $store,
    ) {
    }

    public function __invoke(string $sourceType, string $kind, string $name, string $userId): void
    {
        $operationKind = OperationKind::tryFrom($kind) ?? throw new OzonSourceTypeNotConfirmableException('Выберите вид операции из списка.');
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 255) {
            throw new OzonSourceTypeNotConfirmableException('Название — от 1 до 255 символов.');
        }
        if (!$this->store->confirm($sourceType, $operationKind, $name, $userId)) {
            throw new OzonSourceTypeNotConfirmableException(sprintf('Код %s уже решён или не найден.', $sourceType));
        }
    }
}
