<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Application\GetProductAction;
use App\Catalog\Application\UpdateProductAction;
use App\Catalog\DTO\UpdateProductCommand;
use App\Catalog\Form\ProductType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductEditController extends AbstractController
{
    #[Route('/catalog/products/{id}/edit', name: 'catalog_products_edit', methods: ['GET', 'POST'])]
    public function __invoke(string $id, Request $request, GetProductAction $getProductAction, UpdateProductAction $updateProductAction): Response
    {
        $product = $getProductAction($id);

        $command = new UpdateProductCommand();
        $command->name = $product->getName();
        $command->sku = $product->getSku();
        $command->status = $product->getStatus();
        $command->description = $product->getDescription();
        $command->purchasePrice = $product->getPurchasePrice();

        $form = $this->createForm(ProductType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $updateProductAction($id, $command);

                $this->addFlash('success', 'Товар успешно обновлен.');

                return $this->redirectToRoute('catalog_products_show', ['id' => $id]);
            } catch (\DomainException $exception) {
                $form->get('sku')->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('catalog/product/edit.html.twig', [
            'form' => $form->createView(),
            'product' => $product,
        ]);
    }
}
