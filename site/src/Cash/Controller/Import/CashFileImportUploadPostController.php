<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/file')]
final class CashFileImportUploadPostController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MoneyAccountRepository $accountRepository,
        private readonly \App\Cash\Application\UploadCashFileImportAction $uploadFile,
    ) {
    }

    #[Route('/preview/upload', name: 'cash_file_import_upload_post', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        if (!$this->isCsrfTokenValid('cash_file_import_upload', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Некорректный CSRF-токен.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $file = $request->files->get('import_file');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Загрузите файл для импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $accountId = $request->request->get('money_account_id');
        if (!$accountId) {
            $this->addFlash('error', 'Выберите кассу для импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $company = $this->activeCompanyService->getActiveCompany();
        $account = is_string($accountId) && \App\Cash\Domain\Identifier\CanonicalUuid::isValid($accountId) ? $this->accountRepository->findOneByIdAndCompanyId($accountId, (string) $company->getId()) : null;
        if (!$account) {
            $this->addFlash('error', 'Выбранная касса не найдена.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        try {
            $payload = ($this->uploadFile)($account, $file->getPathname(), $file->getClientOriginalName(), $file->isValid());
        } catch (\App\Cash\Exception\InvalidCashImportFileException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('cash_file_import_upload');
        } catch (\RuntimeException) {
            $this->addFlash('error', 'Не удалось сохранить файл импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $session->set('cash_file_import', $payload);

        return new RedirectResponse('/cash/import/file/mapping', Response::HTTP_SEE_OTHER);
    }
}
