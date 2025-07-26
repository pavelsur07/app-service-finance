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
    public function index(OzonProductRepository $repo, Request $request): Response
    {
        return $this->render('ozon/products.html.twig', [
            'products' => $repo->findBy(['company' => $request->get('company')], ['name' => 'ASC']),
        ]);
    }

    #[Route('/ozon/products/sync', name: 'ozon_products_sync')]
    public function sync(OzonProductSyncService $syncService): Response
    {
        $syncService->sync();
        return $this->redirectToRoute('ozon_products');
    }
}
