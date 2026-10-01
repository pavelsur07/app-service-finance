<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Ozon\Application\Reconciliation\ReconciliationMonth;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonLedgerOperationsQuery;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Query\QueryBuilder;
use Pagerfanta\Doctrine\DBAL\QueryAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Drill-down сверки: записи учёта, из которых сложилась строка (продажи, возвраты, затраты блока/категории).
 */
#[Route('/marketplace/ozon-reconciliation/operations', name: 'marketplace_ozon_reconciliation_operations', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class OzonReconciliationOperationsController extends AbstractController
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly OzonLedgerOperationsQuery $operationsQuery,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->companyService->getActiveCompany()->getId();
        $query = $request->query->all();

        $month = ReconciliationMonth::tryParse($query['month'] ?? null)
            ?? throw new UnprocessableEntityHttpException('Месяц указан неверно, ожидается YYYY-MM.');

        $kind = $query['kind'] ?? null;
        $block = null;
        if (isset($query['block']) && '' !== $query['block']) {
            $block = is_string($query['block']) ? OzonReconciliationBlock::tryFrom($query['block']) : null;
            $block ?? throw new UnprocessableEntityHttpException('Неизвестный блок сверки.');
        }
        $category = $query['category'] ?? null;
        if (null !== $category && (!is_string($category) || 1 !== preg_match('/^[a-z0-9_]{1,64}$/', $category))) {
            throw new UnprocessableEntityHttpException('Неверный код категории.');
        }

        $limit = $this->intParam($query['limit'] ?? self::DEFAULT_LIMIT, 'limit');
        $page = $this->intParam($query['page'] ?? 1, 'page');
        if ($limit < 1 || $limit > self::MAX_LIMIT || $page < 1) {
            throw new UnprocessableEntityHttpException(sprintf('limit должен быть от 1 до %d, page — от 1.', self::MAX_LIMIT));
        }

        $qb = match ($kind) {
            OzonLedgerOperationsQuery::KIND_SALES => $this->operationsQuery->createSalesQueryBuilder($companyId, $month->from, $month->to()),
            OzonLedgerOperationsQuery::KIND_RETURNS => $this->operationsQuery->createReturnsQueryBuilder($companyId, $month->from, $month->to()),
            OzonLedgerOperationsQuery::KIND_COSTS => $this->operationsQuery->createCostsQueryBuilder($companyId, $month->from, $month->to(), $block, is_string($category) ? $category : null),
            default => throw new UnprocessableEntityHttpException('kind: sales, returns или costs.'),
        };

        $adapter = new QueryAdapter($qb, static function (QueryBuilder $qb): void {
            $qb->select('COUNT(*) AS total_results')->resetOrderBy();
        });

        try {
            $pager = Pagerfanta::createForCurrentPageWithMaxPerPage($adapter, $page, $limit);
        } catch (OutOfRangeCurrentPageException) {
            throw new UnprocessableEntityHttpException('Страница вне диапазона.');
        }

        return $this->render('marketplace/ozon_reconciliation/operations.html.twig', [
            'active_tab' => 'ozon_reconciliation',
            'month' => $month->value(),
            'month_label' => $month->label(),
            'kind' => $kind,
            'block' => $block,
            'category' => $category,
            'pager' => $pager,
            'limit' => $limit,
        ]);
    }

    private function intParam(mixed $raw, string $name): int
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && 1 === preg_match('/^\d{1,6}$/', $raw)) {
            return (int) $raw;
        }

        throw new UnprocessableEntityHttpException(sprintf('Параметр %s должен быть числом.', $name));
    }
}
