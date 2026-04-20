<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application;

use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Exception\AdRawDocumentNotFoundException;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Переобработка одного AdRawDocument по запросу из UI.
 *
 * Сбрасывает статус в DRAFT (сбрасывая processingError), коммитит
 * изменение в БД ДО dispatch'а — иначе async-воркер мог бы забрать
 * сообщение и увидеть старый статус. После успешного flush отправляет
 * {@see ProcessAdRawDocumentMessage} в общую очередь.
 *
 * IDOR-контроль — через findByIdAndCompany(); id без companyId-фильтра
 * для наружу-доступных операций недопустим (PATTERNS.md §14).
 *
 * Если документ уже в DRAFT (после краша воркера, например) — всё равно
 * валидный сценарий "повторной обработки", просто skip resetToDraft()
 * (который иначе бросил бы DomainException) и ограничимся dispatch'ом.
 */
final readonly class ReprocessAdRawDocumentAction
{
    public function __construct(
        private AdRawDocumentRepository $rawDocumentRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(string $companyId, string $adRawDocumentId): void
    {
        $document = $this->rawDocumentRepository->findByIdAndCompany($adRawDocumentId, $companyId);

        if (null === $document) {
            throw new AdRawDocumentNotFoundException(sprintf(
                'AdRawDocument %s не найден.',
                $adRawDocumentId,
            ));
        }

        if (AdRawDocumentStatus::DRAFT !== $document->getStatus()) {
            $document->resetToDraft();
            $this->entityManager->flush();
        }

        $this->bus->dispatch(new ProcessAdRawDocumentMessage(
            companyId: $companyId,
            adRawDocumentId: $adRawDocumentId,
        ));
    }
}
