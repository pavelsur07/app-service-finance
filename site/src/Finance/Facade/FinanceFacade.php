<?php

declare(strict_types=1);

namespace App\Finance\Facade;

use App\Cash\Application\DTO\CreateDocumentCommand;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Company\Entity\Company;
use App\Company\Enum\BusinessProfile;
use App\Company\Facade\CompanyFacade;
use App\Company\Repository\CounterpartyRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Finance\Application\Command\CreatePLDocumentCommand;
use App\Finance\Application\Command\CreatePLDocumentOperationCommand;
use App\Finance\Application\CreatePLDocumentAction;
use App\Finance\Application\DeletePLDocumentAction;
use App\Finance\Application\DTO\PlPreviewColumn;
use App\Finance\Application\DTO\PlPreviewResult;
use App\Finance\Application\Service\FinanceDocumentResponsibilityCenterNormalizer;
use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLDocumentSource;
use App\Finance\Enum\PLDocumentStream;
use App\Finance\Exception\PLCategoryCodeAssignmentException;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Finance\Report\PlPreviewCalculator;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Repository\DocumentRepository;
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
        private readonly CounterpartyRepository $counterpartyRepository,
        private readonly ProjectDirectionRepository $projectDirectionRepository,
        private readonly PLCategoryRepository $plCategoryRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly PLRegisterUpdater $plRegisterUpdater,
        private readonly FinanceDocumentResponsibilityCenterNormalizer $responsibilityCenterNormalizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly PlPreviewCalculator $plPreviewCalculator,
        private readonly DefaultPLCategoryTreeProvider $plTemplates,
        private readonly CompanyFacade $companyFacade,
    ) {
    }

    /**
     * Нужно ли дереву ОПиУ компании «Базовый» дополнение статьями маркетплейса: профиль «Базовый»,
     * статьи есть, но не все статьи шаблона «Базовый + маркетплейсы». Подсказка на странице подключений.
     */
    public function needsMarketplacePlExtension(string $companyId): bool
    {
        if (BusinessProfile::UNIVERSAL !== $this->companyFacade->getBusinessProfile($companyId)
            || !$this->plCategoryRepository->existsByCompanyId($companyId)
        ) {
            return false;
        }
        $company = $this->companyFacade->findById($companyId);
        if (null === $company) {
            return false;
        }
        $codes = array_flip($this->plCategoryRepository->findCodesByCompany($company));
        // Только дерево «Базового» (своё дерево дополнение отклонит) и только статьи, которых в
        // «Базовом» нет: удалённая своя статья шаблона не должна держать подсказку вечно.
        if (!isset($codes['GROSS_PROFIT'], $codes['REV_SALES_TOTAL'])) {
            return false;
        }
        $universal = [];
        foreach ($this->plTemplates->nodes(BusinessProfile::UNIVERSAL) as $node) {
            $universal[(string) $node->code] = true;
        }
        foreach ($this->plTemplates->marketplaceExtension() as $node) {
            if (null !== $node->code && !isset($universal[$node->code]) && !isset($codes[$node->code])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Создаёт документ ОПиУ с набором строк.
     *
     * @param string $companyId UUID компании
     * @param PLDocumentSource $source Источник данных (WB, Ozon, manual...)
     * @param PLDocumentStream $stream Поток (revenue, costs, storno)
     * @param string $periodFrom Начало периода (Y-m-d)
     * @param string $periodTo Конец периода (Y-m-d)
     * @param array $entries Массив PLEntryDTO[]
     * @param string|null $projectDirectionId UUID проекта ОПиУ для документа и строк (nullable)
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
                amount: $amount,
                categoryId: $entry->plCategoryId,
                counterpartyId: null,
                projectDirectionId: $entry->projectId ?? $projectDirectionId,
                responsibilityCenterId: null,
                comment: $entry->description,
            );
        }

        $command = new CreatePLDocumentCommand(
            companyId: $companyId,
            date: new \DateTimeImmutable($periodTo),
            type: DocumentType::MARKETPLACE_PL,
            status: DocumentStatus::ACTIVE,
            number: null,
            description: $description,
            counterpartyId: null,
            projectDirectionId: $projectDirectionId,
            responsibilityCenterId: null,
            operations: $operations,
            source: $source,
            stream: $stream,
        );

        return ($this->createAction)($command);
    }

    /**
     * Предпросмотр ОПиУ: дерево статей компании со значениями по колонкам, как если бы суммы
     * колонок провели документом marketplace_pl. Ничего не пишет.
     *
     * Суммы — в знаке строк документа (после is_negative). Сумма на статье, которой нет в
     * компании, которая не строка ввода или без кода, в отчёт не попадёт — она возвращается в
     * PlPreviewResult::$unplaced.
     *
     * @param list<PlPreviewColumn> $columns
     * @param string|null $natureBaseColumn колонка-итог: знак статьи по ней, остальные колонки —
     *                                      линейные доли, складываются в итог
     */
    public function calculatePlPreview(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $columns,
        ?string $natureBaseColumn = null,
    ): PlPreviewResult {
        $company = $this->entityManager->getReference(Company::class, $companyId)
            ?? throw new \InvalidArgumentException(sprintf('Компания %s не найдена.', $companyId));

        return $this->plPreviewCalculator->calculate(
            $company,
            PlReportPeriod::forRange($from, $to),
            $columns,
            $natureBaseColumn,
        );
    }

    /**
     * Присваивает код строке ввода ОПиУ без кода. Отчёт ОПиУ берёт суммы статьи по её коду: у
     * листа без кода суммы в регистре есть, а в отчёте — ноль.
     *
     * Только persist: flush — у вызывающего Action/Command.
     *
     * @return bool true — код присвоен; false — у статьи уже этот код
     *
     * @throws PLCategoryCodeAssignmentException
     */
    public function assignPlCategoryCode(string $companyId, string $categoryId, string $code): bool
    {
        /** @var PLCategory|null $category */
        $category = $this->plCategoryRepository->findOneBy(['id' => $categoryId, 'company' => $companyId]);
        if (null === $category) {
            throw PLCategoryCodeAssignmentException::notFound($categoryId);
        }

        $currentCode = trim((string) $category->getCode());
        if ($currentCode === $code) {
            return false;
        }
        if ('' !== $currentCode) {
            throw PLCategoryCodeAssignmentException::hasOtherCode($categoryId, $currentCode);
        }
        if (PLCategoryType::LEAF_INPUT !== $category->getType()) {
            throw PLCategoryCodeAssignmentException::notLeafInput($categoryId);
        }

        /** @var PLCategory|null $other */
        $other = $this->plCategoryRepository->findOneBy(['company' => $companyId, 'code' => $code]);
        if (null !== $other) {
            throw PLCategoryCodeAssignmentException::codeTaken($code, (string) $other->getId());
        }

        $category->setCode($code);
        $this->entityManager->persist($category);

        return true;
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
     * Транзакцию передаёт вызывающий Action Cash, уже загрузившим её в рамках
     * компании: Finance не читает репозитории Cash (этап 7.2 плана Cash). Связь
     * Document ↔ CashTransaction остаётся ORM-связью сущностей.
     *
     * Без flush() — flush выполняется в вызывающем Action.
     *
     * @throws \DomainException если транзакция не принадлежит компании или не совпадает с командой
     * @throws \DomainException при IDOR-нарушении по контрагенту, проекту или категории
     */
    public function createDocumentFromCashTransaction(
        string $companyId,
        CashTransaction $tx,
        CreateDocumentCommand $command,
    ): string {
        if ($tx->getId() !== $command->cashTransactionId || $tx->getCompany()->getId() !== $companyId) {
            throw new \DomainException('Транзакция ДДС не найдена или не принадлежит компании.');
        }

        $counterparty = null;
        if (null !== $command->counterpartyId) {
            $counterparty = $this->counterpartyRepository->find($command->counterpartyId);
            if (!$counterparty || $counterparty->getCompany()->getId() !== $companyId) {
                throw new \DomainException('Контрагент не найден или не принадлежит компании.');
            }
        }

        $projectDirection = null;
        if (null !== $command->projectDirectionId) {
            $projectDirection = $this->projectDirectionRepository->find($command->projectDirectionId);
            if (!$projectDirection || $projectDirection->getCompany()->getId() !== $companyId) {
                throw new \DomainException('Направление проекта не найдено или не принадлежит компании.');
            }
        }

        $plCategory = null;
        if (null !== $command->plCategoryId) {
            $plCategory = $this->plCategoryRepository->find($command->plCategoryId);
            if (!$plCategory || $plCategory->getCompany()->getId() !== $companyId) {
                throw new \DomainException('Категория ОПиУ не найдена или не принадлежит компании.');
            }
        }

        $document = new Document(Uuid::uuid4()->toString(), $tx->getCompany());
        $document->setDate($command->occurredAt);
        $document->setType(DocumentType::CASHFLOW_EXPENSE);
        $document->setDescription($tx->getDescription());
        $document->setCounterparty($counterparty);
        $document->setProjectDirection($projectDirection);
        $document->setResponsibilityCenterId($command->responsibilityCenterId);

        if ($command->createdWithViolation) {
            $document->markAsCreatedWithViolation();
            $tx->markAsHavingViolatedDocument();
        }

        $operation = new DocumentOperation();
        $operation->setAmount($command->amount);
        $operation->setCounterparty($counterparty);
        $operation->setProjectDirection($projectDirection);
        $operation->setResponsibilityCenterId($command->responsibilityCenterId);
        $operation->setCategory($plCategory);
        $document->addOperation($operation);

        $this->responsibilityCenterNormalizer->prepareExplicitDocument($document, $tx->getCompany());

        $tx->assertCanAllocateAmount((float) $command->amount);

        $tx->addDocument($document);

        $this->entityManager->persist($document);

        return $document->getId();
    }

    /**
     * Обновляет PL-регистр за день документа.
     * Вызывать после flush() в Action.
     *
     * @throws \DomainException если документ не найден
     */
    public function updatePLRegisterForDocument(string $documentId): void
    {
        $document = $this->documentRepository->find($documentId);

        if (!$document) {
            throw new \DomainException('Документ не найден.');
        }

        $this->plRegisterUpdater->updateForDocument($document);
    }
}
