<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Entity\Document;
use App\Finance\Repository\DocumentRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Ручной документ ОПиУ из формы (создание и копия): документ и регистр — один commit, сбой
 * пересчёта не оставляет документ без строк регистра.
 *
 * ID документа — ключ повтора формы: документ своей компании с этим ID уже есть — он и
 * результат, второй не создаётся. Гонку двух одновременных отправок останавливает PK.
 */
final readonly class CreateManualDocumentAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PLRegisterUpdater $plRegisterUpdater,
        private DocumentRepository $documentRepository,
        private ManagerRegistry $managerRegistry,
    ) {
    }

    public function __invoke(Document $document): Document
    {
        $companyId = (string) $document->getCompany()->getId();

        $repeated = $this->documentRepository->findByIdAndCompany((string) $document->getId(), $companyId);
        if ($repeated instanceof Document) {
            // Документ и регистр закоммичены одним действием; сброс кэша безопасно повторить —
            // первый запрос мог упасть сразу после commit.
            $this->plRegisterUpdater->invalidateCacheForCompanyId($companyId);

            return $repeated;
        }

        try {
            $this->entityManager->wrapInTransaction(function () use ($document): void {
                $this->entityManager->persist($document);
                $this->entityManager->flush();

                $this->plRegisterUpdater->updateForDocument($document);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $first = $this->concurrentlyCreated((string) $document->getId(), $companyId, $exception);
            $this->plRegisterUpdater->invalidateCacheForCompanyId($companyId);

            return $first;
        }

        $this->plRegisterUpdater->invalidateCacheForCompanyId($companyId);

        return $document;
    }

    /**
     * Документ, созданный параллельной отправкой той же формы. EntityManager после ошибки flush
     * закрыт — читаем свежим. Ключ чужой компании или другой unique — не повтор: исключение
     * пробрасывается.
     */
    private function concurrentlyCreated(string $id, string $companyId, UniqueConstraintViolationException $exception): Document
    {
        $ownerCompanyId = $this->entityManager->getConnection()->fetchOne(
            'SELECT company_id FROM documents WHERE id = :id',
            ['id' => $id],
        );
        if (false === $ownerCompanyId || (string) $ownerCompanyId !== $companyId) {
            throw $exception;
        }

        $this->managerRegistry->resetManager();
        $entityManager = $this->managerRegistry->getManager();
        \assert($entityManager instanceof EntityManagerInterface);

        $document = $entityManager->getRepository(Document::class)->findOneBy(['id' => $id, 'company' => $companyId]);
        if (!$document instanceof Document) {
            throw $exception;
        }

        return $document;
    }
}
