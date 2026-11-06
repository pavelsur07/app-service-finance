<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Cash\Facade\CashFacade;
use App\Company\Facade\CompanyFacade;
use App\ServiceGateway\Entity\AiCompanyAccess;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /admin/ai/companies/{companyId} — ИИ кабинета: подключение, лимиты и расход, запуски,
 * подсказки за 7 дней. Живёт в Admin: читает ServiceGateway и Cash (Cash сам зависит от
 * ServiceGateway — страница там дала бы цикл модулей).
 */
#[Route('/admin/ai/companies/{companyId}', name: 'admin_ai_company', requirements: ['companyId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class AiCompanyPageController extends AbstractController
{
    public const STATS_DAYS = 7;

    public function __construct(
        private readonly CompanyFacade $companies,
        private readonly AiTaskFacadeInterface $ai,
        private readonly CashFacade $cash,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $companyId): Response
    {
        $company = $this->companies->findById($companyId) ?? throw $this->createNotFoundException('Кабинет не найден.');
        $since = $this->clock->now()->modify(sprintf('-%d days', self::STATS_DAYS));

        return $this->render('admin/ai/company.html.twig', [
            'company' => $company,
            'overview' => $this->ai->companyOverview($companyId),
            'stats' => $this->cash->getAiSuggestionStats($companyId, $since),
            'statsDays' => self::STATS_DAYS,
            'runsSince' => $since,
            'lookbackChoices' => AiCompanyAccess::LOOKBACK_CHOICES,
        ]);
    }
}
