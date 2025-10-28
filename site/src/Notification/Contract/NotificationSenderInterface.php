<?php

// src/Notification/Contract/NotificationSenderInterface.php

namespace App\Notification\Contract;

use App\Notification\DTO\NotificationContext;

interface NotificationSenderInterface
{
    /**
     * Канал, который умеет отправлять (например: 'email', 'telegram').
     */
    public function supports(): string;

    /**
     * Универсальная отправка сообщения для своего канала.
     * Возвращает true при успешной отправке, false — при мягкой ошибке.
     * Генерирует исключение только при фатальной ошибке конфигурации.
     */
    public function send(object $message, NotificationContext $ctx): bool;
}
