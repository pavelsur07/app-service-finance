<?php

namespace App\Controller\Ozon;

use App\Repository\Ozon\OzonProductRepository;
use App\Service\Ozon\OzonProductSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class OzonProductController extends AbstractController
{
    #[Route('/ozon/products', name: 'ozon_products')]
    public function index(
        OzonProductRepository $repo,
        Request $request
    ): Response {
        $company = $this->getUser()->getCompanies()[0];
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;

        $products = $repo->findByCompanyPaginated($company, $page, $limit);
        $total = $repo->countByCompany($company);

        return $this->render('ozon/products.html.twig', [
            'products' => $products,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    #[Route('/ozon/products/sync', name: 'ozon_products_sync')]
    public function sync(
        OzonProductSyncService $syncService
    ): Response {
        $company = $this->getUser()->getCompanies()[0];
        $syncService->sync($company);
        $this->addFlash('success', 'Ozon-товары обновлены!');
        return $this->redirectToRoute('ozon_products');
    }
}
