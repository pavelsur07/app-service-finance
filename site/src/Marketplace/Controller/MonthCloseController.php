<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Application\Command\PreflightMonthCloseCommand;
use App\Marketplace\Application\Command\ReopenMonthStageCommand;
use App\Marketplace\Application\MonthClosePreflightAction;
use App\Marketplace\Application\ReopenMonthStageAction;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\CloseMonthStageMessage;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/month-close')]
#[IsGranted('ROLE_USER')]
final class MonthCloseController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService            $companyService,
        private readonly MonthClosePreflightAction       $preflightAction,
        private readonly ReopenMonthStageAction          $reopenMonthStageAction,
        private readonly MarketplaceMonthCloseRepository $monthCloseRepository,
        private readonly MessageBusInterface             $messageBus,
    ) {
    }

    #[Route('', name: 'marketplace_month_close_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company     = $this->companyService->getActiveCompany();
        $companyId   = (string) $company->getId();
        $marketplace = $request->query->get('marketplace') ?: MarketplaceType::OZON->value;
        $year        = (int) $request->query->get('year', date('Y'));
        $month       = (int) $request->query->get('month', date('n'));

        $marketplaceType = MarketplaceType::tryFrom($marketplace);
        if ($marketplaceType === null) {
            $marketplace     = MarketplaceType::OZON->value;
            $marketplaceType = MarketplaceType::OZON;
        }

        // Текущие статусы закрытия
        $monthClose = $this->monthCloseRepository->findByPeriod(
            $companyId,
            $marketplaceType,
            $year,
            $month,
        );

        // История закрытий по маркетплейсу
        $history = $this->monthCloseRepository->findByCompanyAndMarketplace(
            $companyId,
            $marketplaceType,
        );

        return $this->render('marketplace/month_close/index.html.twig', [
            'active_tab'          => 'month_close',
            'marketplace'         => $marketplace,
            'available_marketplaces' => MarketplaceType::cases(),
            'year'                => $year,
            'month'               => $month,
            'month_close'         => $monthClose,
            'history'             => $history,
            'stages'              => CloseStage::cases(),
        ]);
    }

    #[Route('/preflight', name: 'marketplace_month_close_preflight', methods: ['POST'])]
    public function preflight(Request $request): Response
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $marketplace = (string) $request->request->get('marketplace', '');
        $year        = (int) $request->request->get('year', date('Y'));
        $month       = (int) $request->request->get('month', date('n'));
        $stageValue  = (string) $request->request->get('stage', '');

        $marketplaceType = MarketplaceType::tryFrom($marketplace);
        $stage           = CloseStage::tryFrom($stageValue);

        if ($marketplaceType === null || $stage === null) {
            return $this->json(['error' => 'Некорректные параметры'], 400);
        }

        $command = new PreflightMonthCloseCommand(
            companyId:   $companyId,
            marketplace: $marketplace,
            year:        $year,
            month:       $month,
            stage:       $stage,
        );

        $result = ($this->preflightAction)($command);

        return $this->json([
            'can_close' => $result->canClose(),
            'checks'    => $result->toArray(),
        ]);
    }

    #[Route('/close-stage', name: 'marketplace_month_close_stage', methods: ['POST'])]
    public function closeStage(Request $request): Response
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $user      = $this->getUser();

        $marketplace = (string) $request->request->get('marketplace', '');
        $year        = (int) $request->request->get('year', 0);
        $month       = (int) $request->request->get('month', 0);
        $stageValue  = (string) $request->request->get('stage', '');

        $marketplaceType = MarketplaceType::tryFrom($marketplace);
        $stage           = CloseStage::tryFrom($stageValue);

        if ($marketplaceType === null || $stage === null || $year === 0 || $month === 0) {
            $this->addFlash('error', 'Некорректные параметры запроса.');

            return $this->redirectToRoute('marketplace_month_close_index');
        }

        // Синхронный preflight перед dispatch
        $preflightResult = ($this->preflightAction)(new PreflightMonthCloseCommand(
            companyId:   $companyId,
            marketplace: $marketplace,
            year:        $year,
            month:       $month,
            stage:       $stage,
        ));

        if (!$preflightResult->canClose()) {
            foreach ($preflightResult->getErrors() as $error) {
                $this->addFlash('error', $error->message);
            }

            return $this->redirectToRoute('marketplace_month_close_index', [
                'marketplace' => $marketplace,
                'year'        => $year,
                'month'       => $month,
            ]);
        }

        $this->messageBus->dispatch(new CloseMonthStageMessage(
            companyId:   $companyId,
            marketplace: $marketplace,
            year:        $year,
            month:       $month,
            stage:       $stageValue,
            actorUserId: (string) $user->getId(),
        ));

        $this->addFlash('success', sprintf(
            'Закрытие этапа "%s" за %s %d запущено.',
            $stage->getLabel(),
            $this->getMonthName($month),
            $year,
        ));

        return $this->redirectToRoute('marketplace_month_close_index', [
            'marketplace' => $marketplace,
            'year'        => $year,
            'month'       => $month,
        ]);
    }

    #[Route('/reopen-stage', name: 'marketplace_month_close_reopen', methods: ['POST'])]
    public function reopenStage(Request $request): Response
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $marketplace = (string) $request->request->get('marketplace', '');
        $year        = (int) $request->request->get('year', 0);
        $month       = (int) $request->request->get('month', 0);
        $stageValue  = (string) $request->request->get('stage', '');

        $marketplaceType = MarketplaceType::tryFrom($marketplace);
        $stage           = CloseStage::tryFrom($stageValue);

        if ($marketplaceType === null || $stage === null) {
            $this->addFlash('error', 'Некорректные параметры.');

            return $this->redirectToRoute('marketplace_month_close_index');
        }

        try {
            ($this->reopenMonthStageAction)(new ReopenMonthStageCommand(
                companyId: $companyId,
                marketplace: $marketplace,
                year: $year,
                month: $month,
                stage: $stage,
            ));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('marketplace_month_close_index', [
                'marketplace' => $marketplace,
                'year'        => $year,
                'month'       => $month,
            ]);
        }

        $this->addFlash('success', sprintf(
            'Этап "%s" за %s %d переоткрыт.',
            $stage->getLabel(),
            $this->getMonthName($month),
            $year,
        ));

        return $this->redirectToRoute('marketplace_month_close_index', [
            'marketplace' => $marketplace,
            'year'        => $year,
            'month'       => $month,
        ]);
    }

    private function getMonthName(int $month): string
    {
        $months = [
            1 => 'январь', 2 => 'февраль', 3 => 'март',
            4 => 'апрель', 5 => 'май', 6 => 'июнь',
            7 => 'июль', 8 => 'август', 9 => 'сентябрь',
            10 => 'октябрь', 11 => 'ноябрь', 12 => 'декабрь',
        ];

        return $months[$month] ?? (string) $month;
    }
}
