<?php

namespace App\Cash\Controller\Import;

use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cash/import/file')]
class CashFileImportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
    ) {
    }

    #[Route('', name: 'cash_file_import_upload', methods: ['GET'])]
    public function upload(MoneyAccountRepository $accountRepository): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $accounts = $accountRepository->findBy(['company' => $company]);

        return $this->render('cash/file_import_upload.html.twig', [
            'accounts' => $accounts,
        ]);
    }

    #[Route('/preview/upload', name: 'cash_file_import_upload_post', methods: ['POST'])]
    public function previewUpload(
        Request $request,
        SessionInterface $session,
        MoneyAccountRepository $accountRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('cash_file_import_upload', $request->request->get('_token'))) {
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
        $account = $accountRepository->findOneBy([
            'id' => $accountId,
            'company' => $company,
        ]);

        if (!$account) {
            $this->addFlash('error', 'Выбранная касса не найдена.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $fileContent = file_get_contents($file->getPathname());
        if (false === $fileContent) {
            $this->addFlash('error', 'Не удалось прочитать файл.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $fileHash = hash('sha256', $fileContent);
        $extension = pathinfo($file->getClientOriginalName(), \PATHINFO_EXTENSION);
        $normalizedExtension = '' !== $extension ? strtolower($extension) : '';
        $normalizedExtensionWithDot = '' !== $normalizedExtension ? '.'.$normalizedExtension : '';

        $storageDir = sprintf('%s/var/storage/cash-file-imports', $this->getParameter('kernel.project_dir'));
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            $this->addFlash('error', 'Не удалось подготовить директорию для файлов импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $targetPath = sprintf('%s/%s%s', $storageDir, $fileHash, $normalizedExtensionWithDot);
        if (false === file_put_contents($targetPath, $fileContent)) {
            $this->addFlash('error', 'Не удалось сохранить файл на диск.');

            return $this->redirectToRoute('cash_file_import_upload');
        }

        $session->set('cash_file_import', [
            'file_name' => $file->getClientOriginalName(),
            'file_hash' => $fileHash,
            'stored_ext' => $normalizedExtension,
            'account_id' => $accountId,
        ]);

        return new RedirectResponse('/cash/import/file/mapping', Response::HTTP_SEE_OTHER);
    }
}
