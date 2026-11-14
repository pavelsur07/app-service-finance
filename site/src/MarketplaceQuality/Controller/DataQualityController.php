<?php

declare(strict_types=1);

namespace App\MarketplaceQuality\Controller;

use App\MarketplaceQuality\Application\Service\DataQualityReportBuilder;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отчёт R10 «Качество данных» (`stages/stage-7.md` §7.3) — вкладка экономики маркетплейсов.
 * Окно — WINDOW_DAYS до вчера, как у гейтов.
 */
#[IsGranted('ROLE_COMPANY_USER')]
final class DataQualityController extends AbstractController
{
    public const WINDOW_DAYS = 45;

    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly DataQualityReportBuilder $reports,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/marketplace/economics/quality', name: 'marketplace_economics_quality', methods: ['GET'])]
    public function __invoke(): Response
    {
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();
        $today = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);

        return $this->render('marketplace_quality/index.html.twig', [
            'report' => $this->reports->build($companyId, $today->modify(sprintf('-%d days', self::WINDOW_DAYS)), $today->modify('-1 day')),
        ]);
    }
}
