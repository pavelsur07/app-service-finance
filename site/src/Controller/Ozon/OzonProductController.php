<?php

namespace App\Controller\Ozon;

use App\Api\Ozon\OzonApiClient;
use App\Repository\Ozon\OzonProductRepository;
use App\Service\Ozon\OzonProductSyncService;
use Doctrine\ORM\EntityManagerInterface;
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

    #[Route('/ozon/products-test', name: 'ozon_products_test')]
    public function indexTest(
        OzonProductRepository $repo,
        OzonApiClient         $client,
        Request $request
    ): Response {
        $company = $this->getUser()->getCompanies()[0];

        $products = $client->getAllProductsTest(
            $company->getOzonSellerId(),
            $company->getOzonApiKey()
        );

        return $this->json($products);
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

    #[Route('/ozon/products/clear', name: 'ozon_products_clear', methods: ['POST'])]
    public function clear(
        OzonProductRepository $repo,
        EntityManagerInterface $em,
        Request $request
    ): Response {
        $company = $this->getUser()->getCompanies()[0];

        /*$em = $this->getDoctrine()->getManager();*/
        $products = $repo->findBy(['company' => $company]);
        foreach ($products as $product) {
            $em->remove($product);
        }
        $em->flush();

        $this->addFlash('success', 'Все товары Ozon удалены!');
        return $this->redirectToRoute('ozon_products');
    }
}
