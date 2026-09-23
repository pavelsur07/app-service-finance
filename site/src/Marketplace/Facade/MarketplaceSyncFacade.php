<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\DTO\OzonTotalsCheckDTO;
use App\Marketplace\Application\ProcessRawDocumentAction;
use App\Marketplace\DTO\ActiveSellerConnectionDTO;
use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use App\Marketplace\Repository\OzonTransactionTotalsCheckRepository;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

final readonly class MarketplaceSyncFacade
{
    public function __construct(
        private ActiveSellerConnectionsQuery $activeSellerConnectionsQuery,
        private ProcessRawDocumentAction $processRawDocumentAction,
        private LoggerInterface $connectionsLogger,
        private OzonTransactionTotalsCheckRepository $ozonTotalsCheckRepository,
    ) {
    }

    /**
     * Активные SELLER-подключения ОДНОЙ компании.
     *
     * Через Facade, а не напрямую Query: `Infrastructure/` чужого модуля
     * закрыт, и без этой точки входа Ingestion пришлось бы нарушать границу.
     *
     * Пагинации здесь нет: у компании столько подключений, сколько у неё
     * кабинетов продавца — единицы. Но «единицы» это наблюдение, а не
     * ограничение схемы, поэтому у выборки есть потолок, и его достижение —
     * инцидент, а не молчаливое усечение.
     *
     * @return list<ActiveSellerConnectionDTO>
     */
    public function activeSellerConnections(string $companyId): array
    {
        $cap = ActiveSellerConnectionsQuery::COMPANY_CONNECTIONS_LIMIT;
        $connections = $this->toDtos($this->activeSellerConnectionsQuery->executeForCompany($companyId, $cap));

        // Потолок выборки — ограничение, а не обещание. Молча обрезанный
        // список выглядел бы как «у компании столько подключений и есть», и
        // необработанные кабинеты были бы неотличимы от несуществующих.
        //
        // Запрос отдаёт на строку больше потолка: ровно `cap` подключений —
        // законная граница, и алерт на ней был бы ложным.
        if (count($connections) > $cap) {
            $this->connectionsLogger->error('Active seller connections hit the per-company cap; some cabinets are not processed.', [
                'company_id' => $companyId,
                'cap' => $cap,
            ]);

            return array_slice($connections, 0, $cap);
        }

        return $connections;
    }

    /**
     * Страница реестра подключений ВСЕХ компаний, keyset-курсор по
     * `connectionRef`.
     *
     * @companyScopeExempt Системный обход: cron-командам нужно пройти по всем
     * парам (компания, маркетплейс), и ограничивать выборку одной компанией
     * здесь нечем. Метод назван отдельно и требует явного лимита, чтобы
     * межкомпанейский проход нельзя было получить случайно, попросив «просто
     * подключения».
     *
     * @return list<ActiveSellerConnectionDTO>
     */
    public function activeSellerConnectionsPage(int $limit, ?string $afterConnectionRef = null): array
    {
        return $this->toDtos($this->activeSellerConnectionsQuery->executePage($limit, $afterConnectionRef));
    }

    /**
     * @param list<array{id: string, company_id: string, marketplace: string}> $rows
     *
     * @return list<ActiveSellerConnectionDTO>
     */
    private function toDtos(array $rows): array
    {
        $connections = [];
        foreach ($rows as $row) {
            $connections[] = new ActiveSellerConnectionDTO(
                connectionRef: (string) $row['id'],
                companyId: (string) $row['company_id'],
                marketplace: (string) $row['marketplace'],
            );
        }

        return $connections;
    }

    public function processCostsFromRaw(string $companyId, string $rawDocId): int
    {
        return ($this->processRawDocumentAction)(new ProcessMarketplaceRawDocumentCommand($companyId, $rawDocId, 'costs'));
    }

    /**
     * Последняя контрольная сверка итогов Ozon, пересекающая период компании.
     * Для сверки canon-слоя Ingestion с итогами Ozon (`ReconciliationQuery`).
     */
    public function findLatestOzonTotalsCheck(
        string $companyId,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
    ): ?OzonTotalsCheckDTO {
        Assert::uuid($companyId);

        $check = $this->ozonTotalsCheckRepository->findLatestByCompanyAndPeriod($companyId, $periodFrom, $periodTo);
        if (null === $check) {
            return null;
        }

        return new OzonTotalsCheckDTO($check->getOzonTotals(), $check->getCheckedAt());
    }
}
