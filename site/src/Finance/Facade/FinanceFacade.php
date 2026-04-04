<?php

declare(strict_types=1);

namespace App\Finance\Facade;

use App\Cash\Application\DTO\CreateDocumentCommand;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Repository\CounterpartyRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Finance\Application\Command\CreatePLDocumentCommand;
use App\Finance\Application\Command\CreatePLDocumentOperationCommand;
use App\Finance\Application\CreatePLDocumentAction;
use App\Finance\Application\DeletePLDocumentAction;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLDocumentSource;
use App\Finance\Enum\PLDocumentStream;
use App\Finance\Repository\PLCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Публичный API модуля Finance для других модулей.
 *
 * Finance НЕ знает про маркетплейсы, таблицы Marketplace, маппинги.
 * Принимает универсальные скалярные данные: категория, сумма, период.
 *
 * Использует существующий CreatePLDocumentAction + CreatePLDocumentCommand.
 */
final class FinanceFacade
{
    public function __construct(
        private readonly CreatePLDocumentAction $createAction,
        private readonly DeletePLDocumentAction $deleteAction,
        private readonly CashTransactionRepository $cashTransactionRepository,
        private readonly CounterpartyRepository $counterpartyRepository,
        private readonly ProjectDirectionRepository $projectDirectionRepository,
        private readonly PLCategoryRepository $plCategoryRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Создаёт документ ОПиУ с набором строк.
     *
     * @param string           $companyId          UUID компании
     * @param PLDocumentSource $source             Источник данных (WB, Ozon, manual...)
     * @param PLDocumentStream $stream             Поток (revenue, costs, storno)
     * @param string           $periodFrom         Начало периода (Y-m-d)
     * @param string           $periodTo           Конец периода (Y-m-d)
     * @param array            $entries            Массив PLEntryDTO[]
     * @param string|null      $projectDirectionId UUID проекта ОПиУ для документа и строк (nullable)
     *
     * @return string documentId (UUID созданного документа)
     */
    public function createPLDocument(
        string $companyId,
        PLDocumentSource $source,
        PLDocumentStream $stream,
        string $periodFrom,
        string $periodTo,
        array $entries,
        ?string $projectDirectionId = null,
    ): string {
        $description = sprintf(
            '%s | %s | %s – %s',
            $source->getDisplayName(),
            $stream->getDisplayName(),
            $periodFrom,
            $periodTo,
        );

        // Трансформируем PLEntryDTO[] → CreatePLDocumentOperationCommand[]
        // Проект строки: берём из PLEntryDTO.projectId, fallback — проект документа
        $operations = [];
        foreach ($entries as $entry) {
            $amount = $entry->amount;

            if ($entry->isNegative) {
                $amount = bcmul($amount, '-1', 2);
            }

            $operations[] = new CreatePLDocumentOperationCommand(
                amount:             $amount,
                categoryId:         $entry->plCategoryId,
                counterpartyId:     null,
                projectDirectionId: $entry->projectId ?? $projectDirectionId,
                comment:            $entry->description,
            );
        }

        $command = new CreatePLDocumentCommand(
            companyId:          $companyId,
            date:               new \DateTimeImmutable($periodTo),
            type:               DocumentType::MARKETPLACE_PL,
            status:             DocumentStatus::ACTIVE,
            number:             null,
            description:        $description,
            counterpartyId:     null,
            projectDirectionId: $projectDirectionId,
            operations:         $operations,
            source:             $source,
            stream:             $stream,
        );

        return ($this->createAction)($command);
    }

    /**
     * Удаляет документ ОПиУ и пересчитывает PL-регистр за день документа.
     *
     * Идемпотентен: если документ уже удалён — молча возвращает управление.
     * Используется при переоткрытии этапа «Закрытие месяца».
     *
     * @throws \DomainException если документ не принадлежит компании
     */
    public function deletePLDocument(string $companyId, string $documentId): void
    {
        ($this->deleteAction)($companyId, $documentId);
    }

    /**
     * Создаёт Document + DocumentOperation из транзакции ДДС.
     *
     * Без flush() — flush выполняется в вызывающем Action.
     *
     * @throws \DomainException если транзакция не найдена или не принадлежит компании
     * @throws \DomainException при IDOR-нарушении по контрагенту, проекту или категории
     */
    public function createDocumentFromCashTransaction(
        string $companyId,
        CreateDocumentCommand $command,
    ): string {
        $tx = $this->cashTransactionRepository->find($command->cashTransactionId);

        if (!$tx || $tx->getCompany()->getId() !== $companyId) {
            throw new \DomainException('Транзакция ДДС не найдена или не принадлежит компании.');
        }

        $counterparty = null;
        if ($command->counterpartyId !== null) {
            $counterparty = $this->counterpartyRepository->find($command->counterpartyId);
            if ($counterparty && $counterparty->getCompany()->getId() !== $companyId) {
                throw new \DomainException('Контрагент не принадлежит компании.');
            }
        }

        $projectDirection = null;
        if ($command->projectDirectionId !== null) {
            $projectDirection = $this->projectDirectionRepository->find($command->projectDirectionId);
            if ($projectDirection && $projectDirection->getCompany()->getId() !== $companyId) {
                throw new \DomainException('Направление проекта не принадлежит компании.');
            }
        }

        $plCategory = null;
        if ($command->plCategoryId !== null) {
            $plCategory = $this->plCategoryRepository->find($command->plCategoryId);
            if ($plCategory && $plCategory->getCompany()->getId() !== $companyId) {
                throw new \DomainException('Категория ОПиУ не принадлежит компании.');
            }
        }

        $document = new Document(Uuid::uuid4()->toString(), $tx->getCompany());
        $document->setDate($command->occurredAt);
        $document->setDescription($tx->getDescription());
        $document->setCounterparty($counterparty);
        $document->setProjectDirection($projectDirection);

        if ($command->createdWithViolation) {
            $document->markAsCreatedWithViolation();
        }

        $operation = new DocumentOperation();
        $operation->setAmount($command->amount);
        $operation->setCounterparty($counterparty);
        $operation->setProjectDirection($projectDirection);
        $operation->setCategory($plCategory);
        $document->addOperation($operation);

        $tx->addDocument($document);

        $this->entityManager->persist($document);

        return $document->getId();
    }
}
