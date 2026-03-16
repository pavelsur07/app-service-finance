<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\ReturnsListQuery;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Query\QueryBuilder;
use Pagerfanta\Doctrine\DBAL\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace')]
#[IsGranted('ROLE_USER')]
final class MarketplaceReturnsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly ReturnsListQuery     $returnsListQuery,
    ) {
    }

    #[Route('/returns', name: 'marketplace_returns_index')]
    public function __invoke(Request $request): Response
    {
        $company     = $this->companyService->getActiveCompany();
        $companyId   = (string) $company->getId();
        $marketplace = $request->query->get('marketplace') ?: null;
        $page        = max(1, $request->query->getInt('page', 1));

        $qb      = $this->returnsListQuery->buildQueryBuilder($companyId, $marketplace);
        $adapter = new QueryAdapter($qb, static function (QueryBuilder $qb): void {
            $qb->select('COUNT(r.id)')->resetOrderBy();
        });

        $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(
            $adapter,
            $page,
            50,
        );

        return $this->render('marketplace/returns.html.twig', [
            'pager'                  => $pager,
            'available_marketplaces' => MarketplaceType::cases(),
            'selected_marketplace'   => $marketplace,
        ]);
    }
}
