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
final class MoySkladConnectionsController extends AbstractController
{
    #[Route('/moy-sklad/connections', name: 'moysklad_connections_index', methods: ['GET'])]
    public function __invoke(Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionsQuery $query): Response
    {
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $page = $request->query->getInt('page', 1);
        if ($page < 1) {
            throw new UnprocessableEntityHttpException('Номер страницы должен быть положительным.');
        }
        try {
            $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(new QueryAdapter($query->forCompany($companyId)), $page, 20);
        } catch (OutOfRangeCurrentPageException) {
            throw new UnprocessableEntityHttpException('Страница не существует.');
        }

        return $this->render('moy_sklad/connections/index.html.twig', ['pager' => $pager]);
    }
}
