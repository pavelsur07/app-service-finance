<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller;

use App\MarketplaceAds\Application\ExtractBatchesToRawDocumentsAction;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ручная кнопка «Обработать» на job'е нового cron-driven pipeline (Task-12-test).
 *
 * Распаковывает zip/csv батчей job'а в `AdRawDocument` + диспатч
 * `ProcessAdRawDocumentMessage`. Поведение идемпотентно: повторный POST
 * покажет увеличенный `skipped` и не создаст дубликатов.
 *
 * CSRF: токен сгенерирован шаблоном как `extract-batches-<jobId>`,
 * проверяется до делегирования в Action.
 *
 * IDOR: `companyId` из `ActiveCompanyService` подставляется в
 * `findDownloadableByJobId(jobId, companyId)` — чужой job даёт пустой
 * список batch'ей и `'0 processed'` без exception.
 */
#[IsGranted('ROLE_COMPANY_OWNER')]
final class ExtractBatchesController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ExtractBatchesToRawDocumentsAction $extractAction,
    ) {
    }

    #[Route(
        '/marketplace-ads/jobs/{jobId}/extract-batches',
        name: 'marketplace_ads_extract_batches',
        requirements: ['jobId' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function __invoke(string $jobId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(
            'extract-batches-'.$jobId,
            (string) $request->request->get('_token'),
        )) {
            throw new BadRequestHttpException('Invalid CSRF token');
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        try {
            $stats = ($this->extractAction)($jobId, $companyId);
            $this->addFlash('success', sprintf(
                'Обработка запущена: %d документов в очереди, пропущено %d, ошибок %d',
                $stats['processed'],
                $stats['skipped'],
                $stats['errors'],
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Ошибка обработки: '.$e->getMessage());
        }

        return $this->redirectToRoute('marketplace_ads_index');
    }
}
