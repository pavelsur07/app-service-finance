<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application;

use App\Marketplace\Application\ProcessOzonRealizationAction;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Регрессия PROD-инцидента обработки реализации Ozon.
 *
 * 1. Строка «только возврат» на границе батча сбрасывала EntityManager,
 *    не восстановив кэш листингов. Следующая строка получала detached-прокси
 *    и весь документ падал на финальном flush():
 *    Unable to find "Proxies\__CG__\...\MarketplaceListing" entity identifier
 *    associated with the UnitOfWork.
 *
 * 2. Переобработка удаляла строки периода вне транзакции, поэтому падение
 *    пересоздания оставляло период пустым: строки реализации и связи
 *    pl_document_id терялись безвозвратно.
 */
final class ProcessOzonRealizationRegressionTest extends IntegrationTestCase
{
    /** Батч в ProcessOzonRealizationAction::process(). */
    private const BATCH_SIZE = 250;

    private const SKU = '220280923';
    private const PERIOD_FROM = '2026-01-01';
    private const PERIOD_TO = '2026-01-31';

    private string $companyId;
    private string $rawDocId;

    /**
     * Строка «только возврат» стоит ровно на второй границе батча (500-я),
     * а за ней идёт ещё одна обычная строка — то есть ровно та раскладка,
     * что падала на проде.
     */
    public function testReturnOnlyRowOnBatchBoundaryKeepsListingLink(): void
    {
        $rowCount = self::BATCH_SIZE * 2 + 1;

        $this->seed($this->buildRows($rowCount, self::BATCH_SIZE * 2));

        $result = ($this->action())($this->companyId, $this->rawDocId);

        self::assertSame($rowCount, $result['created']);
        self::assertSame($rowCount, $this->countRows());
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_ozon_realizations
             WHERE raw_document_id = :doc AND listing_id IS NULL',
            ['doc' => $this->rawDocId],
        ));
    }

    /**
     * Переобработка удаляет строки периода и создаёт их заново.
     * Если пересоздание падает, удаление обязано откатиться.
     */
    public function testFailedReprocessKeepsExistingPeriodIntact(): void
    {
        $this->seed($this->buildRows(2, null));

        $action = $this->action();
        ($action)($this->companyId, $this->rawDocId);

        self::assertSame(2, $this->countRows());

        // Имитируем закрытый период: строки связаны с документом ОПиУ.
        // FK у pl_document_id нет, поэтому реальный PLDocument не нужен.
        $plDocumentId = Uuid::uuid4()->toString();
        $this->connection->executeStatement(
            'UPDATE marketplace_ozon_realizations SET pl_document_id = :pl WHERE raw_document_id = :doc',
            ['pl' => $plDocumentId, 'doc' => $this->rawDocId],
        );

        // Тот же документ и тот же период → путь reprocess(): DELETE + пересоздание.
        // Цена второй строки не влезает в NUMERIC(12,2), поэтому INSERT падает
        // уже после того, как DELETE отработал.
        $rows = $this->buildRows(2, null);
        $rows[1]['delivery_commission']['price_per_instance'] = 1e12;
        $this->replaceRawData($rows);

        try {
            ($action)($this->companyId, $this->rawDocId);

            self::fail('Expected the overflowing INSERT to fail, but no exception was thrown.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('overflow', mb_strtolower($e->getMessage()));
        }

        // После упавшего flush EntityManager закрыт — проверяем только через DBAL.
        self::assertSame(2, $this->countRows());
        self::assertSame($plDocumentId, $this->connection->fetchOne(
            'SELECT DISTINCT pl_document_id FROM marketplace_ozon_realizations WHERE raw_document_id = :doc',
            ['doc' => $this->rawDocId],
        ));
    }

    private function action(): ProcessOzonRealizationAction
    {
        return self::getContainer()->get(ProcessOzonRealizationAction::class);
    }

    /**
     * @param int|null $returnOnlyPosition 1-based позиция строки «только возврат»
     *
     * @return list<array<string, mixed>>
     */
    private function buildRows(int $count, ?int $returnOnlyPosition): array
    {
        $item = ['sku' => self::SKU, 'offer_id' => 'offer-1', 'name' => 'Тестовый товар'];

        $rows = [];
        for ($position = 1; $position <= $count; ++$position) {
            // Возврат без продажи: delivery_commission в строке отсутствует.
            $rows[] = $position === $returnOnlyPosition
                ? ['item' => $item, 'return_commission' => ['price_per_instance' => 100.0, 'quantity' => 1]]
                : ['item' => $item, 'delivery_commission' => ['price_per_instance' => 100.0, 'quantity' => 1]];
        }

        return $rows;
    }

    private function seed(array $rows): void
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();

        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku(self::SKU)
            ->build();

        $doc = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('realization')
            ->withPeriod(new \DateTimeImmutable(self::PERIOD_FROM), new \DateTimeImmutable(self::PERIOD_TO))
            ->build();
        $doc->setRawData($this->wrapRows($rows));

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($listing);
        $this->em->persist($doc);
        $this->em->flush();

        $this->companyId = (string) $company->getId();
        $this->rawDocId = (string) $doc->getId();

        $this->em->clear();
    }

    private function replaceRawData(array $rows): void
    {
        $doc = $this->em->find(MarketplaceRawDocument::class, $this->rawDocId);
        self::assertInstanceOf(MarketplaceRawDocument::class, $doc);

        $doc->setRawData($this->wrapRows($rows));
        $this->em->flush();
        $this->em->clear();
    }

    private function wrapRows(array $rows): array
    {
        return [
            'result' => [
                'header' => ['start_date' => self::PERIOD_FROM, 'stop_date' => self::PERIOD_TO],
                'rows' => $rows,
            ],
        ];
    }

    private function countRows(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_ozon_realizations WHERE raw_document_id = :doc',
            ['doc' => $this->rawDocId],
        );
    }
}
