<?php

declare(strict_types=1);

namespace App\Telegram\Facade;

/**
 * Telegram для других модулей: файл пользователям, привязанным к компании через бота.
 */
interface TelegramFacadeInterface
{
    /**
     * Отправляет файл каждому активному пользователю компании у активного бота. HTTP — вне транзакций.
     *
     * @return array{sent: int, failed: int} нет бота или привязок — 0/0
     */
    public function sendDocumentToCompany(string $companyId, string $path, string $fileName, string $caption): array;
}
