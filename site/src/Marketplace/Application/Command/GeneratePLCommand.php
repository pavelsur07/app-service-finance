<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

/**
 * Команда на генерацию документа ОПиУ из данных маркетплейса.
 *
 * Все поля scalar — worker-safe, можно отправить через Messenger.
 */
final class GeneratePLCommand
{
    public function __construct(
        /** UUID компании */
        public readonly string $companyId,

        /** Маркетплейс: 'wildberries', 'ozon', 'yandex_market', 'sber_mega_market' */
        public readonly string $marketplace,

        /** Поток: 'revenue', 'costs', 'storno' */
        public readonly string $stream,

        /** Начало периода (Y-m-d) */
        public readonly string $periodFrom,

        /** Конец периода (Y-m-d) */
        public readonly string $periodTo,

        /** UUID пользователя, инициировавшего генерацию */
        public readonly string $actorUserId,
    ) {
    }
}
