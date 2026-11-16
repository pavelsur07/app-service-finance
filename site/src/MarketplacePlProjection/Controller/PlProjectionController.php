<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Controller;

use App\Finance\Application\DTO\PlPreviewColumn;
use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Facade\LegacyPlPreviewFacadeInterface;
use App\MarketplacePlProjection\Application\Service\ProjectionBuilder;
use App\MarketplacePlProjection\Enum\ProjectionStatus;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «ОПиУ из канона» рядом с текущим расчётом (`docs/tasks/marketplace-pl-projection`): дерево статей
 * компании, колонки — новый расчёт, текущий (предпросмотр легаси) и разница. Ничего не пишет.
 */
#[IsGranted('ROLE_COMPANY_USER')]
final class PlProjectionController extends AbstractController
{
    public const CHANNELS = ['ozon' => 'Ozon', 'wildberries' => 'WB'];
    private const MAX_DAYS = 366;

    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly ProjectionBuilder $projection,
        private readonly LegacyPlPreviewFacadeInterface $legacy,
        private readonly FinanceFacade $finance,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/marketplace/economics/pl', name: 'marketplace_pl_projection', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();
        $today = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0);
        $from = self::date($request->query->getString('from')) ?? $today->modify('first day of this month');
        $to = self::date($request->query->getString('to')) ?? $today;
        $periodError = null;
        if ($from > $to || $from->diff($to)->days >= self::MAX_DAYS) {
            $periodError = 'Период: начало не позже конца, не длиннее года. Показан текущий месяц.';
            [$from, $to] = [$today->modify('first day of this month'), $today];
        }
        $channel = $request->query->getString('channel');
        $channel = isset(self::CHANNELS[$channel]) ? $channel : null;

        $report = $this->projection->build($companyId, $from, $to, $channel);
        $tree = $this->finance->calculatePlPreview($companyId, $from, $to, [
            new PlPreviewColumn('new', $report->amountsByCategory()),
            new PlPreviewColumn('legacy', $this->legacy->amountsByCategory($companyId, $from, $to, $channel)),
        ]);

        return $this->render('marketplace_pl_projection/index.html.twig', [
            'from' => $from,
            'to' => $to,
            'channel' => $channel,
            'channels' => self::CHANNELS,
            'periodError' => $periodError,
            'rows' => $tree->rows,
            'warnings' => $tree->warnings,
            'unplaced' => $tree->unplaced,
            'unmapped' => $report->linesOf(ProjectionStatus::UNMAPPED),
            'excluded' => $report->linesOf(ProjectionStatus::EXCLUDED),
            'missingDerivedCodes' => $report->missingDerivedCodes,
            'daysWithoutCostPrice' => $report->daysWithoutCostPrice,
            'canonMinor' => $report->totalMinor(),
            'mappedMinor' => $report->totalMinor(ProjectionStatus::MAPPED),
            'excludedMinor' => $report->totalMinor(ProjectionStatus::EXCLUDED),
            'unmappedMinor' => $report->totalMinor(ProjectionStatus::UNMAPPED),
        ]);
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Moscow'));

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
