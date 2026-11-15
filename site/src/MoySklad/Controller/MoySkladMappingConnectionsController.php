<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Security\ModuleAccess;
use App\MoySklad\Infrastructure\Query\MoySkladConnectionsQuery;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class MoySkladMappingConnectionsController extends AbstractController
{
    #[Route('/moy-sklad/mappings/{marketplace}', name: 'moysklad_mapping_entry', requirements: ['marketplace' => 'ozon|wildberries'], methods: ['GET'])]
    public function __invoke(string $marketplace, Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionsQuery $query): Response
    {
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $page = $request->query->getInt('page', 1);
        if ($page < 1) {
            throw new UnprocessableEntityHttpException('Неверный номер страницы.');
        }
        try {
            $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(new QueryAdapter($query->forCompany($companyId)), $page, 20);
        } catch (OutOfRangeCurrentPageException) {
            throw new UnprocessableEntityHttpException('Страница не существует.');
        }
        if (1 === $pager->getNbResults()) {
            $connection = iterator_to_array($pager->getCurrentPageResults())[0];

            return $this->redirectToRoute('moysklad_listing_mappings', ['connectionId' => $connection->getId(), 'marketplace' => $marketplace]);
        }

        return $this->render('moy_sklad/mappings/connections.html.twig', ['pager' => $pager, 'mappingMarketplace' => $marketplace]);
    }
}
