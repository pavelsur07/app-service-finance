<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Inventory;

use App\Marketplace\Inventory\Application\Command\SetInventoryCostPriceCommand;
use App\Marketplace\Inventory\Application\SetInventoryCostPriceAction;
use App\Marketplace\Inventory\Infrastructure\Query\InventoryCostListingQuery;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Query\QueryBuilder;
use Pagerfanta\Doctrine\DBAL\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    ) {
    }

    #[Route('', name: 'marketplace_inventory_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company     = $this->companyService->getActiveCompany();
        $companyId   = (string) $company->getId();
        $marketplace = $request->query->get('marketplace') ?: null;
        $page        = max(1, (int) $request->query->get('page', 1));

        $qb = $this->query->listingsQueryBuilder($companyId, $marketplace);

        $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(
            new QueryAdapter($qb, static function (QueryBuilder $qb): void {
                $qb->select('COUNT(DISTINCT l.id) AS total_results')
                    ->resetOrderBy()
                    ->setMaxResults(1);
            }),
            $page,
            30,
        );

        return $this->render('marketplace/inventory/index.html.twig', [
            'active_tab'  => 'inventory',
            'pager'       => $pager,
            'marketplace' => $marketplace,
        ]);
    }

    #[Route('/{id}/history', name: 'marketplace_inventory_history', methods: ['GET'])]
    public function history(string $id): Response
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $meta = $this->query->findListingMeta($companyId, $id);
        if ($meta === null) {
            throw $this->createNotFoundException('Листинг не найден.');
        }

        $history = $this->query->fetchHistory($companyId, $id);

        return $this->render('marketplace/inventory/history.html.twig', [
            'active_tab' => 'inventory',
            'listing'    => $meta,
            'history'    => $history,
        ]);
    }

    #[Route('/{id}/set-cost', name: 'marketplace_inventory_set_cost', methods: ['POST'])]
    public function setCost(string $id, Request $request): Response
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $priceAmount   = (string) $request->request->get('price_amount', '');
        $effectiveFrom = (string) $request->request->get('effective_from', '');
        $note          = (string) $request->request->get('note', '') ?: null;

        try {
            $command = new SetInventoryCostPriceCommand(
                companyId:     $companyId,
                listingId:     $id,
                effectiveFrom: new \DateTimeImmutable($effectiveFrom),
                priceAmount:   $priceAmount,
                currency:      'RUB',
                note:          $note,
            );

            ($this->setAction)($command);

            $this->addFlash('success', 'Себестоимость сохранена.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка сохранения: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_inventory_history', ['id' => $id]);
    }
}
