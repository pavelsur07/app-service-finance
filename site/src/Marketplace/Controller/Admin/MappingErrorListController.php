<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Admin;

use App\Marketplace\Repository\MappingErrorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список неизвестных затрат маркетплейса для мониторинга.
 *
 * GET /admin/marketplace/mapping-errors
 */
#[Route('/admin/marketplace/mapping-errors', name: 'admin_marketplace_mapping_errors', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class MappingErrorListController extends AbstractController
{
    public function __construct(
        private readonly MappingErrorRepository $repository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $showAll = $request->query->getBoolean('all', false);

        $errors = $showAll
            ? $this->repository->findAllWithCompanyInfo()
            : $this->repository->findUnresolvedWithCompanyInfo();

        $unresolvedCount = count($this->repository->findUnresolvedWithCompanyInfo());

        return $this->render('marketplace/admin/mapping_error_list.html.twig', [
            'errors'           => $errors,
            'show_all'         => $showAll,
            'unresolved_count' => $unresolvedCount,
        ]);
    }
}
