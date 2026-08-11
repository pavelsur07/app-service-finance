<?php

declare(strict_types=1);

namespace App\Company\Security;

/**
 * Пометка контроллера/экшена: модульный гейт (ModuleAccessSubscriber) не применяется.
 * Для публичных маршрутов из access_control (логин, регистрация, инвайт, webhook, health, /api/public/*).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class PublicAccess
{
}
