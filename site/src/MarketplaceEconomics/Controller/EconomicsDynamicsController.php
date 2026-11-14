<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Controller;

use App\Marketplace\Facade\ListingTagFacade;
use App\MarketplaceEconomics\Application\DTO\EconomicsDynamicsReport;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsFilter;
use App\MarketplaceEconomics\Application\Service\EconomicsDynamicsReportBuilder;
use App\MarketplaceEconomics\Exception\InvalidEconomicsPeriodException;
use App\MarketplaceEconomics\Infrastructure\Export\SkuEconomicsXlsxExporter;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отчёт R2 «Динамика» на новом контуре (`stages/stage-6.md` §6.4): те же фильтры, что у R1, плюс шаг.
 * `?format=xlsx` — та же выборка файлом.
 */
#[IsGranted('ROLE_COMPANY_USER')]
final class EconomicsDynamicsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly EconomicsDynamicsReportBuilder $reports,
        private readonly SkuEconomicsXlsxExporter $exporter,
        private readonly ListingTagFacade $tags,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/marketplace/economics/dynamics', name: 'marketplace_economics_dynamics', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();
        $today = $this->clock->now()->setTime(0, 0);
        $query = $request->query;
        $from = (string) $query->get('from', $today->modify('-90 days')->format('Y-m-d'));
        $to = (string) $query->get('to', $today->modify('-1 day')->format('Y-m-d'));
        $step = (string) $query->get('step', 'week');

        $view = [
            'from' => $from,
            'to' => $to,
            'step' => \in_array($step, EconomicsDynamicsReport::STEPS, true) ? $step : 'week',
            'connection' => (string) $query->get('connection', ''),
            'tagIds' => array_values(array_filter(array_map('strval', $query->all('tags')))),
            'search' => trim((string) $query->get('q', '')),
            'allocated' => '0' !== $query->get('alloc'),
            'allTags' => $this->tags->list($companyId),
            'report' => null,
            'error' => null,
        ];

        try {
            $filter = new SkuEconomicsFilter(
                $this->date($from), $this->date($to), '' === $view['connection'] ? null : $view['connection'],
                $view['tagIds'], $view['search'],
            );
            $report = $this->reports->build($companyId, $filter, $view['step']);
            if ('xlsx' === $query->get('format')) {
                return $this->exporter->dynamicsResponse($report, $view['allocated']);
            }
            $view['report'] = $report;
        } catch (InvalidEconomicsPeriodException $e) {
            $view['error'] = $e->getMessage();

            return $this->render('marketplace_economics/dynamics.html.twig', $view, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('marketplace_economics/dynamics.html.twig', $view);
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidEconomicsPeriodException('Дата — в формате ГГГГ-ММ-ДД.');
        }

        return $date;
    }
}
