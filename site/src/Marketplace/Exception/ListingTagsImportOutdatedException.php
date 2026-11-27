<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class ListingTagsImportOutdatedException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Теги листингов изменились после предпросмотра. Загрузите файл заново.');
    }
}
