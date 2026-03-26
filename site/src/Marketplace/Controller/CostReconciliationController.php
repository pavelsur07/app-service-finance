<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Application\ReconcileCostsAction;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Загрузка xlsx и запуск сверки затрат маркетплейса.
 *
 * POST /marketplace/month-close/reconcile
 */
#[Route('/marketplace/month-close/reconcile', name: 'marketplace_month_close_reconcile', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
final class CostReconciliationController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly ReconcileCostsAction $reconcileCostsAction,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company     = $this->companyService->getActiveCompany();
        $companyId   = (string) $company->getId();
        $marketplace = (string) $request->request->get('marketplace', MarketplaceType::OZON->value);
        $year        = (int) $request->request->get('year');
        $month       = (int) $request->request->get('month');
        $file        = $request->files->get('xlsx_file');

        if (MarketplaceType::tryFrom($marketplace) === null || $year === 0 || $month === 0 || $file === null) {
            $this->addFlash('error', 'Некорректные параметры или файл не загружен.');

            return $this->redirectToRoute('marketplace_month_close_index', [
                'marketplace' => $marketplace,
                'year'        => $year,
                'month'       => $month,
            ]);
        }

        try {
            $result = ($this->reconcileCostsAction)(
                $companyId, $marketplace, $year, $month, $file,
            );

            if ($result['status'] === 'matched') {
                $this->addFlash('success', sprintf(
                    'Сверка выполнена: данные совпадают. Delta: %s руб.',
                    number_format(abs($result['delta']), 2, '.', ' '),
                ));
            } else {
                $this->addFlash('warning', sprintf(
                    'Сверка выполнена: расхождение %s руб. Проверьте данные.',
                    number_format(abs($result['delta']), 2, '.', ' '),
                ));
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Ошибка сверки: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_month_close_index', [
            'marketplace' => $marketplace,
            'year'        => $year,
            'month'       => $month,
        ]);
    }
}
