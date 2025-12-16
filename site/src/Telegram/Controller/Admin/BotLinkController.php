<?php

namespace App\Telegram\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
// Раздел админских ссылок отключён: генерация и управление перенесены
// в кабинеты компаний (/integrations/telegram). Маршруты намеренно
// удалены, чтобы админка управляла только глобальным ботом.
class BotLinkController extends AbstractController
{
    // Контроллер оставлен пустым, чтобы явно показать отключение админского
    // раздела ссылок и избежать повторной регистрации маршрутов.
}
