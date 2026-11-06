<?php

declare(strict_types=1);

namespace App\Settlement\Application;

use App\Cash\Facade\CashFacade;
use App\Company\Facade\CompanyFacade;
use App\Finance\Facade\FinanceFacade;
use App\Settlement\Application\DTO\SettlementRegisterProjection;
use App\Settlement\Application\DTO\SettlementRegisterVerification;
use App\Settlement\Enum\SettlementSourceType;
use App\Settlement\Query\SettlementRegisterProjectionQuery;
use App\Settlement\Repository\SettlementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/** CLI maintenance boundary. Production execution requires separate named owner approval. */
final readonly class ReconcileSettlementRegisterAction
{
    public function __construct(private EntityManagerInterface $em, private CompanyFacade $companies, private CashFacade $cash, private FinanceFacade $finance, private SettlementRepository $repository, private SettlementRegisterProjectionQuery $projection)
    {
    }

    public function __invoke(string $companyId, bool $execute = false): SettlementRegisterVerification
    {
        return $this->em->wrapInTransaction(function () use ($companyId, $execute): SettlementRegisterVerification {
            $this->companies->lockFinancialMaintenance($companyId);
            // Company lock freezes the set of settlement primary facts. Sources always precede book.
            $expected = $this->projection->project($companyId);
            if ($execute) {
                $this->lockAndValidateSources($companyId, $expected);
            }
            $this->repository->getBookForUpdate($companyId);
            $db = $this->em->getConnection();
            $expectedRows = [];
            foreach ($expected->movements as $m) {
                $expectedRows[$m->getDocumentId().':'.$m->getItemId().':'.$m->getBucket()->value] = [$m->getEffectiveDate()->format('Y-m-d'), (string) $m->getDelta()->amountMinor(), $m->getDelta()->currency()];
            }
            $actualRows = [];
            foreach ($db->fetchAllAssociative('SELECT document_id,item_id,bucket,effective_date,delta_amount_minor,delta_currency FROM settlement_movements WHERE company_id=?', [$companyId]) as $row) {
                $actualRows[$row['document_id'].':'.$row['item_id'].':'.$row['bucket']] = [$row['effective_date'], (string) $row['delta_amount_minor'], $row['delta_currency']];
            }
            $expectedLinks = [];
            foreach ($expected->links as $link) {
                $expectedLinks[$link->getSourceType()->value.':'.$link->getSourceId()] = [$link->getActiveDocumentId(), $link->getLastReversalDate()?->format('Y-m-d')];
            }
            $actualLinks = [];
            foreach ($db->fetchAllAssociative('SELECT source_type,source_id,active_document_id,last_reversal_date FROM settlement_source_links WHERE company_id=?', [$companyId]) as $row) {
                $actualLinks[$row['source_type'].':'.$row['source_id']] = [$row['active_document_id'], $row['last_reversal_date']];
            }
            ksort($expectedRows);
            ksort($actualRows);
            ksort($expectedLinks);
            ksort($actualLinks);
            $differences = [];
            if ($expectedRows !== $actualRows) {
                $differences[] = 'Движения не совпадают с первичными документами.';
            }
            if ($expectedLinks !== $actualLinks) {
                $differences[] = 'Связи источников не совпадают с первичными документами.';
            }
            if ($execute && [] !== $differences) {
                $db->executeStatement('DELETE FROM settlement_movements WHERE company_id=?', [$companyId]);
                foreach ($expected->movements as $m) {
                    $db->insert('settlement_movements', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'document_id' => $m->getDocumentId(), 'item_id' => $m->getItemId(), 'bucket' => $m->getBucket()->value, 'effective_date' => $m->getEffectiveDate()->format('Y-m-d'), 'delta_amount_minor' => $m->getDelta()->amountMinor(), 'delta_currency' => $m->getDelta()->currency()]);
                }
                $db->executeStatement('DELETE FROM settlement_source_links WHERE company_id=?', [$companyId]);
                foreach ($expected->links as $link) {
                    $db->insert('settlement_source_links', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'source_type' => $link->getSourceType()->value, 'source_id' => $link->getSourceId(), 'active_document_id' => $link->getActiveDocumentId(), 'last_reversal_date' => $link->getLastReversalDate()?->format('Y-m-d')]);
                }
                // DBAL replaced derived rows; avoid stale instances on the next request/action in this process.
                $this->em->clear();
            }

            return new SettlementRegisterVerification(count($expected->movements), count($expected->links), $differences);
        });
    }

    private function lockAndValidateSources(string $companyId, SettlementRegisterProjection $expected): void
    {
        $cashIds = [];
        $financeIds = [];
        foreach ($expected->activeSources as $doc) {
            $id = $doc->getSourceId();
            if (null === $id) {
                throw new \DomainException('Источник отсутствует.');
            }
            if (SettlementSourceType::CASH === $doc->getSourceType()) {
                $cashIds[] = $id;
            } else {
                $financeIds[] = $id;
            }
        }
        $cashIds = array_values(array_unique([...$cashIds, ...$this->finance->getSettlementSourceCashIds($companyId, $financeIds)]));
        sort($cashIds);
        sort($financeIds);
        $fingerprints = [];
        foreach ($cashIds as $id) {
            $fingerprints['cash:'.$id] = $this->cash->getSettlementSourceForUpdate($companyId, $id)->fingerprint;
        }
        foreach ($financeIds as $id) {
            $fingerprints['finance:'.$id] = $this->finance->getSettlementSourceForUpdate($companyId, $id, $cashIds)->fingerprint;
        }
        foreach ($expected->activeSources as $key => $doc) {
            if (($doc->getSourceSnapshot()['fingerprint'] ?? null) !== ($fingerprints[$key] ?? null)) {
                throw new \DomainException('Активный источник изменён: требуется разбор первичных документов.');
            }
        }
    }
}
