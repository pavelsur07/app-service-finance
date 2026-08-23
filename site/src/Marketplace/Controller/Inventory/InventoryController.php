<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Inventory;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Enum\JobType;
use App\Marketplace\Inventory\Application\Command\SetInventoryCostPriceCommand;
use App\Marketplace\Inventory\Application\SetInventoryCostPriceAction;
use App\Marketplace\Inventory\Infrastructure\Query\InventoryCostListingQuery;
use App\Marketplace\Message\RecalculateListingCostPriceMessage;
use App\Marketplace\Message\SyncOzonListingBarcodesMessage;
use App\Marketplace\Repository\MarketplaceJobLogRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Query\QueryBuilder;
use Pagerfanta\Doctrine\DBAL\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/inventory')]
#[IsGranted('ROLE_USER')]
final class InventoryController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly InventoryCostListingQuery $query,
        private readonly SetInventoryCostPriceAction $setAction,
        private readonly MarketplaceJobLogRepository $jobLogRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'marketplace_inventory_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $rawQuery = $request->query->all();
        $marketplace = self::stringOrNull($rawQuery['marketplace'] ?? null);
        $search = self::stringOrNull($rawQuery['q'] ?? null);
        $pageRaw = self::stringOrNull($rawQuery['page'] ?? null);
        $page = max(1, (int) ($pageRaw ?? '1'));

        if (null !== $search) {
            $search = trim($search);
            if ('' === $search) {
                $search = null;
            }
        }

        $qb = $this->query->listingsQueryBuilder($companyId, $marketplace, $search);

        $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(
            new QueryAdapter($qb, static function (QueryBuilder $qb): void {
                $qb->select('COUNT(DISTINCT l.id) AS total_results')
                    ->resetOrderBy()
                    ->setMaxResults(1);
            }),
            $page,
            30,
        );

        $jobLogs = $this->jobLogRepository->findLastByJobTypes($companyId, [
            JobType::BARCODE_SYNC_OZON,
            JobType::COST_PRICE_IMPORT,
        ]);

        return $this->render('marketplace/inventory/index.html.twig', [
            'active_tab' => 'inventory',
            'pager' => $pager,
            'marketplace' => $marketplace,
            'search' => $search,
            'job_logs' => $jobLogs,
        ]);
    }

    #[Route('/{id}/history', name: 'marketplace_inventory_history', methods: ['GET'])]
    public function history(string $id): Response
    {
        $company = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $meta = $this->query->findListingMeta($companyId, $id);
        if (null === $meta) {
            throw $this->createNotFoundException('Листинг не найден.');
        }

        $history = $this->query->fetchHistory($companyId, $id);

        return $this->render('marketplace/inventory/history.html.twig', [
            'active_tab' => 'inventory',
            'listing' => $meta,
            'history' => $history,
        ]);
    }

    #[Route('/{id}/set-cost', name: 'marketplace_inventory_set_cost', methods: ['POST'])]
    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function setCost(string $id, Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('marketplace_inventory_set_cost'.$id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        $priceAmount = (string) $request->request->get('price_amount', '');
        $effectiveFrom = (string) $request->request->get('effective_from', '');
        $note = (string) $request->request->get('note', '') ?: null;

        try {
            $effectiveFromDate = new \DateTimeImmutable($effectiveFrom);
            $command = new SetInventoryCostPriceCommand(
                companyId: $companyId,
                listingId: $id,
                effectiveFrom: $effectiveFromDate,
                priceAmount: $priceAmount,
                currency: 'RUB',
                note: $note,
            );

            $result = ($this->setAction)($command);
            $today = $this->clock->now();
            $this->messageBus->dispatch(new RecalculateListingCostPriceMessage(
                companyId: $companyId,
                marketplace: $result->marketplace->value,
                listingIds: [$id],
                dateFrom: $today->modify('first day of this month')->format('Y-m-d'),
                dateTo: $today->format('Y-m-d'),
                actorUserId: (string) $user->getId(),
            ));

            if ($result->wasOverwritten) {
                $this->addFlash('warning', sprintf(
                    'Себестоимость на %s уже существовала и была перезаписана. Пересчёт текущего месяца запущен.',
                    $effectiveFromDate->format('d.m.Y'),
                ));
            } else {
                $this->addFlash('success', 'Себестоимость сохранена. Пересчёт текущего месяца запущен.');
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка сохранения: '.$e->getMessage());
        }

        if ('history' === $request->request->get('return_to')) {
            return $this->redirectToRoute('marketplace_inventory_history', ['id' => $id]);
        }

        $referer = (string) $request->headers->get('referer', '');
        $urlParts = '' !== $referer ? (parse_url($referer) ?: []) : [];
        $path = is_string($urlParts['path'] ?? null) ? $urlParts['path'] : '';

        if (str_contains($path, '/marketplace/inventory/'.$id.'/history')) {
            return $this->redirectToRoute('marketplace_inventory_history', ['id' => $id]);
        }

        $params = [];
        $query = is_string($urlParts['query'] ?? null) ? $urlParts['query'] : '';
        if ('' !== $query) {
            parse_str($query, $parsed);
            foreach (['marketplace', 'page', 'q'] as $key) {
                if (isset($parsed[$key]) && is_string($parsed[$key]) && '' !== $parsed[$key]) {
                    $params[$key] = $parsed[$key];
                }
            }
        }

        return $this->redirectToRoute('marketplace_inventory_index', $params);
    }

    #[Route('/sync-barcodes', name: 'marketplace_inventory_sync_barcodes', methods: ['POST'])]
    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function syncBarcodes(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        if (!$this->isCsrfTokenValid('marketplace_inventory_sync_barcodes', (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        $this->messageBus->dispatch(new SyncOzonListingBarcodesMessage(
            companyId: $companyId,
        ));

        $this->addFlash('success', 'Синхронизация баркодов Ozon запущена. Данные обновятся в течение нескольких секунд.');

        return $this->redirectToRoute('marketplace_inventory_index');
    }

    private static function stringOrNull(mixed $raw): ?string
    {
        return is_string($raw) && '' !== $raw ? $raw : null;
    }
}
