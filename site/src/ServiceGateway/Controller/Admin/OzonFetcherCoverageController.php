<?php

declare(strict_types=1);

namespace App\ServiceGateway\Controller\Admin;

use App\ServiceGateway\Application\Service\CoverageHeatmapBuilder;
use App\ServiceGateway\Infrastructure\Client\OzonFetcherCoverageClient;
use App\ServiceGateway\Infrastructure\Query\ServiceCoverageMonthQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «Покрытие Сервис GO»: тепловая карта загрузки ozon-fetcher за месяц.
 * Читает только витрину (docs/architecture/service-gateway.md §2.6) —
 * открывается и при недоступном сервисе. Месяцы — окно сервиса M-2…M.
 */
#[Route('/admin/services/ozon-fetcher/coverage', name: 'admin_service_gateway_ozon_fetcher_coverage', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class OzonFetcherCoverageController extends AbstractController
{
    private const BUSINESS_TIMEZONE = 'Europe/Moscow';
    private const MONTHS_BACK = 2;

    public function __construct(
        private readonly ServiceCoverageMonthQuery $query,
        private readonly CoverageHeatmapBuilder $builder,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone(self::BUSINESS_TIMEZONE));
        $first = $now->modify('first day of this month')->setTime(0, 0);
        $months = [];
        for ($i = 0; $i <= self::MONTHS_BACK; ++$i) {
            $months[] = $first->modify(sprintf('-%d months', $i))->format('Y-m');
        }

        $month = $request->query->getString('month', $months[0]);
        if ('' === $month) {
            $month = $months[0];
        }
        if (!\in_array($month, $months, true)) {
            $this->addFlash('warning', 'Покрытие хранится только за текущий и два прошлых месяца — открыт текущий.');

            return $this->redirectToRoute('admin_service_gateway_ozon_fetcher_coverage');
        }

        $heatmap = $this->builder->build($this->query->fetch(OzonFetcherCoverageClient::SERVICE, $month), $now);

        return $this->render('admin/service_gateway/ozon_fetcher_coverage.html.twig', [
            'heatmap' => $heatmap,
            'months' => $months,
            'stale_minutes' => intdiv(CoverageHeatmapBuilder::STALE_AFTER_SECONDS, 60),
        ]);
    }
}
