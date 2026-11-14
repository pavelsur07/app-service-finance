<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Controller;

use App\Marketplace\Facade\ListingTagFacade;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsFilter;
use App\MarketplaceEconomics\Application\Service\SkuEconomicsReportBuilder;
use App\MarketplaceEconomics\Exception\InvalidEconomicsPeriodException;
use App\MarketplaceEconomics\Infrastructure\Export\SkuEconomicsXlsxExporter;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отчёт R1 «Экономика SKU» на новом контуре (`stages/stage-5.md` §2.3). Работает параллельно легаси
 * `/marketplace-analytics/unit-extended`. `?format=xlsx` — та же выборка файлом.
 */
#[IsGranted('ROLE_COMPANY_USER')]
final class SkuEconomicsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly SkuEconomicsReportBuilder $reports,
        private readonly SkuEconomicsXlsxExporter $exporter,
        private readonly ListingTagFacade $tags,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/marketplace/economics', name: 'marketplace_economics_sku', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();
        $today = $this->clock->now()->setTime(0, 0);
        $query = $request->query;
        $from = (string) $query->get('from', $today->modify('-30 days')->format('Y-m-d'));
        $to = (string) $query->get('to', $today->modify('-1 day')->format('Y-m-d'));
        $view = [
            'from' => $from,
            'to' => $to,
            'connection' => (string) $query->get('connection', ''),
            'tagIds' => array_values(array_filter(array_map('strval', $query->all('tags')))),
            'search' => trim((string) $query->get('q', '')),
            'sort' => \in_array($query->get('sort'), SkuEconomicsFilter::SORTS, true) ? (string) $query->get('sort') : 'md2',
            'desc' => 'asc' !== $query->get('dir'),
            'allocated' => '0' !== $query->get('alloc'),
            'allTags' => $this->tags->list($companyId),
            'report' => null,
            'pager' => null,
            'error' => null,
        ];

        try {
            $limit = $query->getInt('limit', 50);
            if ($limit < 1 || $limit > 200) {
                throw new InvalidEconomicsPeriodException('Количество строк — от 1 до 200.');
            }
            $filter = new SkuEconomicsFilter(
                $this->date($from), $this->date($to), '' === $view['connection'] ? null : $view['connection'],
                $view['tagIds'], $view['search'], $view['sort'], $view['desc'],
            );
            $report = $this->reports->build($companyId, $filter);
            if ('xlsx' === $query->get('format')) {
                return $this->exporter->response($report, $view['allocated']);
            }
            $pager = new Pagerfanta(new ArrayAdapter($report->rows));
            $pager->setMaxPerPage($limit);
            $page = $query->getInt('page', 1);
            if ($page < 1 || $page > $pager->getNbPages()) {
                throw new InvalidEconomicsPeriodException('Запрошенная страница не существует.');
            }
            $pager->setCurrentPage($page);
            $view['report'] = $report;
            $view['pager'] = $pager;
        } catch (InvalidEconomicsPeriodException $e) {
            $view['error'] = $e->getMessage();

            return $this->render('marketplace_economics/sku.html.twig', $view, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('marketplace_economics/sku.html.twig', $view);
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
