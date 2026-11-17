<?php

declare(strict_types=1);

namespace App\Cash\Exception;

/** Ручная привязка статьи ДДС к эталону группы отклонена; сообщение — для пользователя. */
final class CashflowGroupChartBindingException extends \RuntimeException
{
}
