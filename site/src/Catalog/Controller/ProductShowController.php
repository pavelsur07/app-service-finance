<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Application\GetProductAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Webmozart\Assert\Assert;

final class ProductShowController extends AbstractController
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    #[Route('/catalog/products/{id}', name: 'catalog_products_show', methods: ['GET'])]
    public function __invoke(string $id, GetProductAction $getProductAction): Response
    {
        Assert::uuid($id);
        $product = $getProductAction($id);

        return $this->render('catalog/product/show.html.twig', [
            'product' => $product,
            'canEditProduct' => null !== $this->router->getRouteCollection()->get('catalog_products_edit'),
        ]);
    }
}

