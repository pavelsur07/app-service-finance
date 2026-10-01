<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Ozon\Application\Reconciliation\OzonReconciliationViewFactory;
use App\Marketplace\Ozon\Application\Reconciliation\ReconciliationMonth;
use App\Marketplace\Repository\OzonReconciliationLineRepository;
use App\Marketplace\Repository\OzonReconciliationRunRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Вкладка «Сверка Ozon»: последний снимок сверки за выбранный месяц.
 */
#[Route('/marketplace/ozon-reconciliation', name: 'marketplace_ozon_reconciliation_index', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class OzonReconciliationController extends AbstractController
{
    private const TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly OzonReconciliationRunRepository $runRepository,
        private readonly OzonReconciliationLineRepository $lineRepository,
        private readonly OzonReconciliationViewFactory $viewFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->companyService->getActiveCompany()->getId();

        $current = ReconciliationMonth::containing($this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE)));
        $month = ReconciliationMonth::tryParse($request->query->all()['month'] ?? null) ?? $current;

        $run = $this->runRepository->findByPeriod($companyId, $month->from, $month->to());
        $view = null === $run
            ? null
            : $this->viewFactory->create($run, $this->lineRepository->findByRun($companyId, $run->getId()));

        // В списке месяцев — все месяцы со снимками плюс текущий и выбранный.
        $months = [$current->value() => $current->label(), $month->value() => $month->label()];
        foreach ($this->runRepository->findRecentByCompany($companyId) as $recent) {
            $recentMonth = ReconciliationMonth::containing($recent->getPeriodFrom());
            $months[$recentMonth->value()] = $recentMonth->label();
        }
        krsort($months);

        return $this->render('marketplace/ozon_reconciliation/index.html.twig', [
            'active_tab' => 'ozon_reconciliation',
            'month' => $month->value(),
            'month_label' => $month->label(),
            'months' => $months,
            'view' => $view,
        ]);
    }
}
