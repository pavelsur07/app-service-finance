<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application;

use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Exception\AdRawDocumentNotFoundException;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Переобработка одного AdRawDocument по запросу из UI.
 *
 * Сбрасывает статус в DRAFT (сбрасывая processingError) и коммитит изменение.
 *
 * ПАРСИНГ ВРЕМЕННО ОТКЛЮЧЁН (task-8, 23.04.2026):
 * ProcessAdRawDocumentMessage больше не диспатчится. Сейчас UI-кнопка
 * «Переобработать» эффективно сбрасывает документ в DRAFT, но async-обработка
 * не запускается — файл уже сохранён на диске и доступен через кнопку
 * «Открыть». Возобновим диспатч, когда вернём парсер.
 *
 * IDOR-контроль — через findByIdAndCompany(); id без companyId-фильтра
 * для наружу-доступных операций недопустим (PATTERNS.md §14).
 *
 * Если документ уже в DRAFT (после краша воркера, например) — всё равно
 * валидный сценарий "повторной обработки", просто skip resetToDraft()
 * (который иначе бросил бы DomainException).
 */
final readonly class ReprocessAdRawDocumentAction
{
    public function __construct(
        private AdRawDocumentRepository $rawDocumentRepository,
        private EntityManagerInterface $entityManager,
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
    }
}
