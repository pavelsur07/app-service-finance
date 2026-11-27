<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\DTO\ListingTagsFileRow;
use App\Marketplace\Application\ImportListingTagsAction;
use App\Marketplace\Exception\InvalidListingTagsFileException;
use App\Marketplace\Infrastructure\Import\ListingTagsFileReader;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Загрузка файла разметки тегов → предпросмотр обоих режимов, без записи. */
#[Route('/marketplace/listings/tags-file/upload', name: 'marketplace_listing_tags_file_upload', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class ListingTagsFileUploadController extends AbstractController
{
    public const string CSRF_ID = 'listing_tags_file';
    private const int MAX_FILE_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly ListingTagsFileReader $reader,
        private readonly ImportListingTagsAction $importTags,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->companyService->getActiveCompany()->getId();

        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        $file = $request->files->get('tags_file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->fail('Файл не загружен.');
        }
        if ('xlsx' !== strtolower($file->getClientOriginalExtension())) {
            return $this->fail('Нужен файл xlsx — скачайте его кнопкой «Скачать для разметки».');
        }
        if ($file->getSize() > self::MAX_FILE_BYTES) {
            return $this->fail('Файл больше 2 МБ. Разбейте его на части.');
        }

        try {
            $rows = $this->reader->read($file->getPathname());
            $add = ($this->importTags)($companyId, $rows, false);
            $replace = ($this->importTags)($companyId, $rows, true);
        } catch (InvalidListingTagsFileException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->render('marketplace/listings/tags_import.html.twig', [
            'active_tab' => 'listings',
            'fileName' => $file->getClientOriginalName(),
            'rowsCount' => \count($rows),
            'add' => $add,
            'replace' => $replace,
            'rowsJson' => json_encode(
                array_map(static fn (ListingTagsFileRow $row): array => [$row->rowNumber, $row->listingId, $row->tags], $rows),
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE,
            ),
        ]);
    }

    private function fail(string $message): Response
    {
        $this->addFlash('danger', $message);

        return $this->redirectToRoute('marketplace_listings_index');
    }
}
