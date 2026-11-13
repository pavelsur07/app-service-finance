<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Repository\Import\CashFileImportProfileRepository;
use App\Cash\Infrastructure\Import\File\FileTabularReader;
use App\Cash\Application\Service\Import\File\HeaderAutoMapper;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\TemporaryLocalFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/file')]
final class CashFileImportMappingController extends AbstractController
{
    public function __construct(
        private readonly \App\Cash\Http\CashImportSession $importSession,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ObjectStorageInterface $storage,
        private readonly TemporaryLocalFile $temporaryLocalFile,
        private readonly FileTabularReader $fileTabularReader,
        private readonly HeaderAutoMapper $headerAutoMapper,
        private readonly CashFileImportProfileRepository $profileRepository,
    ) {
    }

    #[Route('/mapping', name: 'cash_file_import_mapping', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        $importState = $this->importSession->read($session, $this->activeCompanyService->getActiveCompany());
        if (null === $importState) {
            $this->addFlash('error', 'Сессия импорта устарела или относится к другому счёту/компании. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $importPayload = $importState->payload;
        $fileHash = $importPayload['file_hash'] ?? null;
        $storedExtension = $importPayload['stored_ext'] ?? null;
        if (!$fileHash || !is_string($fileHash)) {
            $this->addFlash('error', 'Не удалось определить файл импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $storageKey = \App\Cash\Application\Service\Import\File\CashFileImportService::storageKey($fileHash, is_string($storedExtension) ? $storedExtension : '');
        if (!$this->storage->exists($storageKey)) {
            $this->addFlash('error', 'Файл импорта не найден. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        [$headers, $sampleRows] = $this->temporaryLocalFile->with($storageKey, fn (string $filePath): array => [$this->fileTabularReader->readHeader($filePath), $this->fileTabularReader->readSampleRows($filePath)]);
        $mapping = [];
        if (isset($importPayload['mapping']) && is_array($importPayload['mapping'])) {
            $mapping = $importPayload['mapping'];
        }
        if ([] === $mapping) {
            $mapping = $this->headerAutoMapper->suggest($headers);
        }
        $company = $this->activeCompanyService->getActiveCompany();
        $profiles = $this->profileRepository->findByCompanyAndType($company, CashFileImportProfile::TYPE_CASH_TRANSACTION);

        return $this->render('cash/file_import_mapping.html.twig', ['fileName' => $importPayload['file_name'] ?? '', 'headers' => $headers, 'sampleRows' => $sampleRows, 'mapping' => $mapping, 'profiles' => $profiles, 'selectedProfileId' => $importPayload['profile_id'] ?? null]);
    }
}
