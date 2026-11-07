<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Query;

use App\Cash\Facade\CashFacade;
use App\Finance\Entity\Document;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLDocumentSource;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;

final readonly class FinanceSettlementSourceQuery
{
    /** @param list<string> $documentIds
     * @return list<string>
     */
    public function getCashIds(string $companyId, array $documentIds): array
    {
        if ([] === $documentIds) {
            return [];
        }

        return $this->entityManager->getConnection()->fetchFirstColumn('SELECT DISTINCT cash_transaction_id FROM documents WHERE company_id=? AND id IN (?) AND cash_transaction_id IS NOT NULL ORDER BY cash_transaction_id', [$companyId, $documentIds], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING]);
    }

    public function __construct(private EntityManagerInterface $entityManager, private CashFacade $cashFacade)
    {
    }

    /** @param ?list<string> $prelockedCashIds */
    public function getForUpdate(string $companyId, string $documentId, ?array $prelockedCashIds = null): \App\Finance\Application\DTO\FinanceSettlementSource
    {
        $row = $this->lockHeaderForUpdate($companyId, $documentId, $prelockedCashIds);
        $db = $this->entityManager->getConnection();
        $cashId = $row['cash_transaction_id'];
        $operations = $db->fetchAllAssociative('SELECT id, amount, category_id, project_direction_id, responsibility_center_id FROM document_operations WHERE document_id = :id ORDER BY id', ['id' => $documentId]);

        return new \App\Finance\Application\DTO\FinanceSettlementSource((string) $row['id'], $companyId, null === $row['counterparty_id'] ? null : (string) $row['counterparty_id'], new \DateTimeImmutable((string) $row['date']), null !== $row['deleted_at'], DocumentStatus::from((string) $row['status']), DocumentType::from((string) $row['type']), array_map(static fn (array $operation): string => (string) $operation['id'], $operations), $this->fingerprint($row, $operations), null === $row['source'] ? null : PLDocumentSource::from((string) $row['source']), null === $cashId ? null : (string) $cashId);
    }

    /** @param ?list<string> $prelockedCashIds
     * @return array<string, mixed>
     */
    public function lockHeaderForUpdate(string $companyId, string $documentId, ?array $prelockedCashIds = null): array
    {
        $db = $this->entityManager->getConnection();
        if (!$db->isTransactionActive()) {
            throw new \LogicException('Snapshot requires a transaction.');
        }
        $cashId = $db->fetchOne('SELECT cash_transaction_id FROM documents WHERE company_id = :company AND id = :id', ['company' => $companyId, 'id' => $documentId]);
        if (false === $cashId) {
            throw new \DomainException('Документ ОПиУ не найден.');
        }
        if (null !== $prelockedCashIds && null !== $cashId && !in_array($cashId, $prelockedCashIds, true)) {
            throw new \DomainException('Связь ОПиУ с ДДС изменилась: повторите сверку.');
        }
        if (null !== $cashId) {
            $this->cashFacade->getSettlementSourceForUpdate($companyId, (string) $cashId);
        }
        $row = $db->fetchAssociative('SELECT id, company_id, counterparty_id, date, deleted_at, status, type, source, cash_transaction_id, project_direction_id, responsibility_center_id FROM documents WHERE company_id = :company AND id = :id FOR UPDATE', ['company' => $companyId, 'id' => $documentId]);
        if (false === $row || $row['cash_transaction_id'] !== $cashId) {
            throw new \DomainException('Документ ОПиУ изменён параллельно.');
        }

        return $row;
    }

    public function fingerprintDocument(Document $document): string
    {
        $header = ['id' => $document->getId(), 'company_id' => $document->getCompany()->getId(), 'counterparty_id' => $document->getCounterparty()?->getId(), 'date' => $document->getDate()->format('Y-m-d'), 'deleted_at' => $document->getDeletedAt()?->format('Y-m-d H:i:s'), 'status' => $document->getStatus()->value, 'type' => $document->getType()->value, 'source' => $document->getSource()?->value, 'cash_transaction_id' => $document->getCashTransaction()?->getId(), 'project_direction_id' => $document->getProjectDirection()?->getId(), 'responsibility_center_id' => $document->getResponsibilityCenterId()];
        $operations = [];
        foreach ($document->getOperations() as $operation) {
            $operations[] = ['id' => $operation->getId(), 'amount' => $operation->getAmount(), 'category_id' => $operation->getCategory()?->getId(), 'project_direction_id' => $operation->getProjectDirection()?->getId(), 'responsibility_center_id' => $operation->getResponsibilityCenterId()];
        }

        return $this->fingerprint($header, $operations);
    }

    /** @param array<string, mixed> $header
     * @param list<array<string, mixed>> $operations
     */
    private function fingerprint(array $header, array $operations): string
    {
        $header['date'] = (new \DateTimeImmutable((string) $header['date']))->format('Y-m-d');
        usort($operations, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
        foreach ($operations as &$operation) {
            // First-release RUB normalization only; this does not derive the obligation amount.
            $operation['amount'] = Money::fromString((string) $operation['amount'], 'RUB')->toDecimalString();
        }
        unset($operation);

        return hash('sha256', json_encode([$header, $operations], \JSON_THROW_ON_ERROR));
    }
}
