<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\Category\ImportCashflowCategoryTreeAction;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Применение показанного в предпросмотре плана загрузки дерева статей ДДС. */
#[IsGranted('ROLE_USER')]
#[Route('/cashflow-categories')]
final class CashflowCategoryImportApplyController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly ImportCashflowCategoryTreeAction $import,
    ) {
    }

    #[Route('/import/apply', name: 'cashflow_category_import_apply', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        if (!$this->isCsrfTokenValid('cashflow-category-import-apply'.$company->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Форма устарела, загрузите файл заново.');

            return $this->redirectToRoute('cashflow_category_import');
        }

        try {
            $json = (string) $request->request->get('payload');
            $payload = json_decode(\strlen($json) > 2 * 1_048_576 ? '' : $json, true, 32, \JSON_THROW_ON_ERROR);
            $newIds = array_values(array_filter(explode(',', (string) $request->request->get('new_ids'))));
            $plan = ($this->import)($company, $payload, $newIds, (string) $request->request->get('fingerprint'));
        } catch (\JsonException|\DomainException $exception) {
            $this->addFlash('danger', $exception instanceof \DomainException ? $exception->getMessage() : 'Форма повреждена, загрузите файл заново.');

            return $this->redirectToRoute('cashflow_category_import');
        }

        $this->addFlash('success', [] === $plan->items
            ? 'Изменений нет: дерево статей уже соответствует файлу.'
            : sprintf('Дерево статей загружено: добавлено %d, изменено %d.', \count($plan->created()), \count($plan->updated())));

        return $this->redirectToRoute('cashflow_category_index');
    }
}
