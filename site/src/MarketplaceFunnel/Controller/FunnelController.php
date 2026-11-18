<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Controller;

use App\Company\Security\ModuleAccess;
use App\MarketplaceFunnel\Application\Service\FunnelReportBuilder;
use App\MarketplaceFunnel\Infrastructure\Export\FunnelXlsxExporter;
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
 * Экран R9 «Воронка»: товар × ступени за период по Ozon и WB (`docs/tasks/marketplace-funnel`, этап 6).
 * `?format=xlsx` — та же выборка файлом.
 */
#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class FunnelController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly FunnelReportBuilder $reports,
        private readonly FunnelXlsxExporter $exporter,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/marketplace/funnel', name: 'marketplace_funnel', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();
        $today = $this->clock->now()->setTime(0, 0);
        $query = $request->query;
        $view = [
            'from' => (string) $query->get('from', $today->modify('-30 days')->format('Y-m-d')),
            'to' => (string) $query->get('to', $today->modify('-1 day')->format('Y-m-d')),
            'channel' => (string) $query->get('channel', ''),
            'search' => trim((string) $query->get('q', '')),
            'sort' => (string) $query->get('sort', 'orders'),
            'desc' => 'asc' !== $query->get('dir'),
            'report' => null,
            'pager' => null,
            'error' => null,
        ];

        try {
            $report = $this->reports->build($companyId, $this->date($view['from']), $this->date($view['to']), '' === $view['channel'] ? null : $view['channel'], $view['search'], $view['sort'], $view['desc']);
            if ('xlsx' === $query->get('format')) {
                return $this->exporter->response($report);
            }
            $pager = new Pagerfanta(new ArrayAdapter($report->rows));
            $pager->setMaxPerPage(self::PER_PAGE);
            $page = $query->getInt('page', 1);
            if ($page < 1 || $page > $pager->getNbPages()) {
                throw new \InvalidArgumentException('Запрошенная страница не существует.');
            }
            $pager->setCurrentPage($page);
            $view['report'] = $report;
            $view['pager'] = $pager;
        } catch (\InvalidArgumentException $e) {
            $view['error'] = $e->getMessage();

            return $this->render('marketplace_funnel/index.html.twig', $view, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('marketplace_funnel/index.html.twig', $view);
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Дата — в формате ГГГГ-ММ-ДД.');
        }

        return $date;
    }
}
