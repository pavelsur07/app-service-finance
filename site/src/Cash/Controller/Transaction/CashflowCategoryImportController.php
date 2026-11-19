<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\Category\ImportCashflowCategoryTreeAction;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Загрузка дерева статей ДДС из JSON: выбор файла и предпросмотр. Ничего не пишет. */
#[IsGranted('ROLE_USER')]
#[Route('/cashflow-categories')]
final class CashflowCategoryImportController extends AbstractController
{
    private const MAX_BYTES = 1_048_576;

    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly ImportCashflowCategoryTreeAction $import,
    ) {
    }

    #[Route('/import', name: 'cashflow_category_import', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $view = ['companyId' => (string) $company->getId(), 'plan' => null, 'errors' => [], 'fileName' => null, 'payload' => null];
        if (!$request->isMethod('POST')) {
            return $this->render('cashflow_category/import.html.twig', $view);
        }

        $file = $request->files->get('import_file');
        if (!$this->isCsrfTokenValid('cashflow-category-import'.$company->getId(), (string) $request->request->get('_token'))) {
            $view['errors'] = ['Форма устарела, обновите страницу.'];
        } elseif (!$file instanceof UploadedFile || !$file->isValid()) {
            $view['errors'] = ['Выберите файл выгрузки.'];
        } elseif ($file->getSize() > self::MAX_BYTES) {
            $view['errors'] = ['Файл больше 1 МБ.'];
        } else {
            $view['fileName'] = $file->getClientOriginalName();
            try {
                $payload = json_decode($file->getContent(), true, 32, \JSON_THROW_ON_ERROR);
                $plan = $this->import->preview($company, $payload);
                $view['errors'] = $plan->errors;
                $view['plan'] = [] === $plan->errors ? $plan : null;
                $view['payload'] = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            } catch (\JsonException) {
                $view['errors'] = ['Файл не JSON или повреждён.'];
            }
        }

        return $this->render('cashflow_category/import.html.twig', $view);
    }
}
