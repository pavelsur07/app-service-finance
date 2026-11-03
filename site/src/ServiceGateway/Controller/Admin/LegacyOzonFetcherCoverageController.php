<?php

declare(strict_types=1);

namespace App\ServiceGateway\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Прежний адрес страницы покрытия (только ozon-fetcher) — постоянный редирект
 * на вкладку Ozon общей страницы с тем же месяцем.
 */
#[Route('/admin/services/ozon-fetcher/coverage', name: 'admin_service_gateway_ozon_fetcher_coverage', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class LegacyOzonFetcherCoverageController extends AbstractController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $params = ['service' => 'ozon-fetcher'];
        $month = $request->query->getString('month');
        if ('' !== $month) {
            $params['month'] = $month;
        }

        return $this->redirectToRoute('admin_service_gateway_coverage', $params, Response::HTTP_MOVED_PERMANENTLY);
    }
}
