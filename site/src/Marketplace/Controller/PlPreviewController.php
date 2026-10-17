<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\Action\BuildMarketplacePlPreviewAction;
use App\Marketplace\Application\PlPreview\PlPreviewFilter;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplacePlPreviewPeriodException;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Вкладка «Предпросмотр ОПиУ»: что покажет ОПиУ по тегам товара, если провести данные периода по
 * текущему маппингу. Ничего не пишет.
 */
#[Route('/marketplace/pl-preview', name: 'marketplace_pl_preview_index', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class PlPreviewController extends AbstractController
{
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly BuildMarketplacePlPreviewAction $buildPreview,
        private readonly MarketplaceListingTagRepository $tagRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->companyService->getActiveCompany()->getId();
        $filter = PlPreviewFilter::fromQuery(
            $request->query->all(),
            $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE)),
        );

        $report = null;
        $error = null;
        try {
            $report = ($this->buildPreview)($filter->toCommand($companyId));
        } catch (MarketplacePlPreviewPeriodException $e) {
            $error = $e->getMessage();
        }

        return $this->render('marketplace/pl_preview/index.html.twig', [
            'active_tab' => 'pl_preview',
            'filter' => $filter,
            'report' => $report,
            'error' => $error,
            'tags' => $this->tagRepository->listForCompany($companyId),
            'marketplaces' => MarketplaceType::cases(),
        ]);
    }
}
