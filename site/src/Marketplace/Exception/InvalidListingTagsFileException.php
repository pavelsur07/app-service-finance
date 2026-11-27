<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

/** Файл разметки тегов не читается: не тот формат, нет колонок, слишком много строк. */
final class InvalidListingTagsFileException extends \DomainException
{
}
