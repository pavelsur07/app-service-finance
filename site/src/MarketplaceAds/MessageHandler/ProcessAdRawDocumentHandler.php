<?php

declare(strict_types=1);

namespace App\MarketplaceAds\MessageHandler;

use App\MarketplaceAds\Application\ProcessAdRawDocumentAction;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\AppLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async-обработчик {@see ProcessAdRawDocumentMessage}.
 *
 * - Не предполагает Request/Session/Security (CLI worker context).
 * - Всегда перечитывает AdRawDocument по id + companyId, т.к. между dispatch и handler
 *   состояние документа могло измениться.
 * - Идемпотентен: если документ уже обработан (не в DRAFT) — молча возвращается.
 * - Ошибки Action пере­брасываются для retry по стратегии Messenger.
 */
#[AsMessageHandler]
final class ProcessAdRawDocumentHandler
{
    public function __construct(
        private readonly AdRawDocumentRepository $rawDocumentRepository,
        private readonly ProcessAdRawDocumentAction $processAction,
        private readonly AppLogger $logger,
    ) {}

    public function __invoke(ProcessAdRawDocumentMessage $message): void
    {
        $rawDocument = $this->rawDocumentRepository->findByIdAndCompany(
            $message->adRawDocumentId,
            $message->companyId,
        );

        if ($rawDocument === null) {
            $this->logger->warning('AdRawDocument не найден при async-обработке', [
                'companyId'       => $message->companyId,
                'adRawDocumentId' => $message->adRawDocumentId,
            ]);

            return;
        }

        if ($rawDocument->getStatus() !== AdRawDocumentStatus::DRAFT) {
            $this->logger->info('AdRawDocument уже обработан, повторный запуск пропущен', [
                'companyId'       => $message->companyId,
                'adRawDocumentId' => $message->adRawDocumentId,
                'status'          => $rawDocument->getStatus()->value,
            ]);

            return;
        }

        try {
            ($this->processAction)($message->companyId, $message->adRawDocumentId);
        } catch (\DomainException $e) {
            // DomainException из Action означает гонку состояний: другой worker/диспатч успел
            // обработать документ (status != DRAFT) или сам документ удалили между pre-check
            // и вызовом Action. Ретрай Messenger здесь только шумит в failed-queue — поглощаем.
            $this->logger->info(
                'AdRawDocument обработан параллельно или удалён, повтор не нужен',
                [
                    'companyId'       => $message->companyId,
                    'adRawDocumentId' => $message->adRawDocumentId,
                    'reason'          => $e->getMessage(),
                ],
            );

            return;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Ошибка обработки AdRawDocument',
                $e,
                [
                    'companyId'       => $message->companyId,
                    'adRawDocumentId' => $message->adRawDocumentId,
                ],
            );
            throw $e; // перебросить — Messenger сделает retry по стратегии async-транспорта
        }
    }
}
