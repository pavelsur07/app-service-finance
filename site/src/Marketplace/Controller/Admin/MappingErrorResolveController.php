<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Admin;

use App\Marketplace\Repository\MappingErrorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отметить ошибку маппинга как решённую.
 *
 * POST /admin/marketplace/mapping-errors/{id}/resolve
 */
#[Route('/admin/marketplace/mapping-errors/{id}/resolve', name: 'admin_marketplace_mapping_error_resolve', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class MappingErrorResolveController extends AbstractController
{
    public function __construct(
        private readonly MappingErrorRepository  $repository,
        private readonly EntityManagerInterface  $em,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $error = $this->repository->find($id);

        if ($error === null) {
            throw $this->createNotFoundException('Ошибка маппинга не найдена.');
        }

        $error->resolve();
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Ошибка «%s» отмечена как решённая.',
            $error->getServiceName(),
        ));

        return $this->redirectToRoute('admin_marketplace_mapping_errors');
    }
}
