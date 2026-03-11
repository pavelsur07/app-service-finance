<?php

declare(strict_types=1);

namespace App\Catalog\MessageHandler;

use App\Catalog\Application\Command\ImportProductsCommand;
use App\Catalog\Application\ImportProductsFromXlsAction;
use App\Catalog\Message\ImportProductsMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обрабатывает асинхронный импорт товаров.
 *
 * ❌ ActiveCompanyService здесь ЗАПРЕЩЁН — нет HTTP-сессии в Worker.
 * ✅ companyId приходит из Message как string — согласно правилам разработки.
 */
#[AsMessageHandler]
final class ImportProductsMessageHandler
{
    public function __construct(
        private readonly ImportProductsFromXlsAction $importAction,
    ) {
    }

    public function __invoke(ImportProductsMessage $message): void
    {
        ($this->importAction)(new ImportProductsCommand(
            companyId: $message->companyId,
            importId:  $message->importId,
        ));
    }
}
