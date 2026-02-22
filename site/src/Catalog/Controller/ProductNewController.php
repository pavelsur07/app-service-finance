<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Application\CreateProductAction;
use App\Catalog\DTO\CreateProductCommand;
use App\Catalog\Enum\ProductStatus;
use App\Catalog\Form\ProductType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductNewController extends AbstractController
{
    #[Route('/catalog/products/new', name: 'catalog_products_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, CreateProductAction $createProductAction): Response
    {
        $command = new CreateProductCommand();
        $command->status = ProductStatus::ACTIVE;

        $form = $this->createForm(ProductType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $productId = $createProductAction($command);

                $this->addFlash('success', 'Товар успешно создан.');

                return $this->redirectToRoute('catalog_products_show', ['id' => $productId]);
            } catch (\DomainException $exception) {
                $form->get('sku')->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('catalog/product/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
