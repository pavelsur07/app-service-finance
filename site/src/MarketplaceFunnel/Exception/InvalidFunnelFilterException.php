<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Exception;

/** Фильтр экрана воронки не принят: сообщение показывается пользователю. */
final class InvalidFunnelFilterException extends \DomainException
{
}
