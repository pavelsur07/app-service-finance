<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Application\RecalculateSalesDocumentsCostPriceAction;
use App\Marketplace\DTO\RecalculateSalesCostPriceCommand;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace')]
#[IsGranted('ROLE_USER')]
class RecalculateSalesCostPriceController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly RecalculateSalesDocumentsCostPriceAction $recalculateAction,
    ) {
    }

    #[Route('/recalculate-cost-price', name: 'marketplace_recalculate_sales_cost_price', methods: ['POST'])]
    public function recalculate(Request $request): Response
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $marketplace  = $request->request->get('marketplace');
        $dateFrom     = $request->request->get('date_from');
        $dateTo       = $request->request->get('date_to');
        $onlyZeroCost = (bool) $request->request->get('only_zero_cost', false);
        $redirectTo   = $request->request->get('redirect_to', 'marketplace_sales_index');

        // Валидация
        $marketplaceType = $marketplace ? MarketplaceType::tryFrom($marketplace) : null;
        if (!$marketplaceType) {
            $this->addFlash('error', 'Укажите маркетплейс');
            return $this->redirectToRoute($redirectTo);
        }

        try {
            $dateFromObj = new \DateTimeImmutable($dateFrom);
            $dateToObj   = new \DateTimeImmutable($dateTo . ' 23:59:59');
        } catch (\Exception) {
            $this->addFlash('error', 'Неверный формат дат');
            return $this->redirectToRoute($redirectTo);
        }

        if ($dateFromObj > $dateToObj) {
            $this->addFlash('error', 'Дата начала не может быть позже даты конца');
            return $this->redirectToRoute($redirectTo);
        }

        try {
            $cmd = new RecalculateSalesCostPriceCommand(
                companyId:    $companyId,
                marketplace:  $marketplaceType,
                dateFrom:     $dateFromObj,
                dateTo:       $dateToObj,
                onlyZeroCost: $onlyZeroCost,
            );

            $result = ($this->recalculateAction)($cmd);

            $this->addFlash('success', sprintf(
                'Себестоимость пересчитана: продаж — %d, возвратов — %d.',
                $result['sales'],
                $result['returns'],
            ));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка пересчёта: ' . $e->getMessage());
        }

        return $this->redirectToRoute($redirectTo);
    }
}
