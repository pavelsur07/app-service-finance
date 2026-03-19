<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Company\Facade\CompanyFacade;
use App\Repository\DocumentRepository;
use App\Service\PLRegisterUpdater;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Удаляет документ ОПиУ и пересчитывает PL-регистр за день документа.
 *
 * Используется при переоткрытии этапа «Закрытие месяца» —
 * чтобы устранить задвоение документов при повторном закрытии.
 *
 * Проверяет принадлежность документа компании перед удалением.
 * Worker-safe: принимает только scalar companyId и documentId.
 */
final class DeletePLDocumentAction
{
    public function __construct(
        private readonly DocumentRepository    $documentRepository,
        private readonly EntityManagerInterface $em,
        private readonly PLRegisterUpdater     $plRegisterUpdater,
        private readonly CompanyFacade         $companyFacade,
    ) {
    }

    /**
     * @throws \DomainException если документ не найден или не принадлежит компании
     */
    public function __invoke(string $companyId, string $documentId): void
    {
        $document = $this->documentRepository->find($documentId);

        if ($document === null) {
            // Документ уже удалён — идемпотентно, не ошибка
            return;
        }

        if ((string) $document->getCompany()->getId() !== $companyId) {
            throw new \DomainException(sprintf(
                'Document "%s" does not belong to company "%s".',
                $documentId,
                $companyId,
            ));
        }

        // Запоминаем дату ДО удаления — нужна для пересчёта регистра
        $documentDate = $document->getDate()->setTime(0, 0);

        $this->em->remove($document);
        $this->em->flush();

        // Пересчитываем PL-регистр за день удалённого документа.
        // Метод recalcRange принимает Company — получаем через Facade.
        $company = $this->companyFacade->findById($companyId);
        if ($company === null) {
            // Компания удалена — регистр пересчитывать незачем
            return;
        }

        $this->plRegisterUpdater->recalcRange($company, $documentDate, $documentDate);
    }
}
