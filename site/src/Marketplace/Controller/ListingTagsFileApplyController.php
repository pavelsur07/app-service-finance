<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\DTO\ListingTagsFileRow;
use App\Marketplace\Application\ImportListingTagsAction;
use App\Marketplace\Exception\InvalidListingTagsFileException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Применение разметки из предпросмотра. Строки файла приходят из скрытого поля и
 * проверяются заново тем же Action; записывается только план с показанным отпечатком.
 */
#[Route('/marketplace/listings/tags-file/apply', name: 'marketplace_listing_tags_file_apply', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class ListingTagsFileApplyController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly ImportListingTagsAction $importTags,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->companyService->getActiveCompany()->getId();

        if (!$this->isCsrfTokenValid(ListingTagsFileUploadController::CSRF_ID, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        $replace = 'replace' === $request->request->get('mode');
        $fingerprint = (string) $request->request->get($replace ? 'fingerprintReplace' : 'fingerprintAdd', '');

        try {
            $result = ($this->importTags)($companyId, self::rows((string) $request->request->get('rows', '')), $replace, $fingerprint);
        } catch (\DomainException $e) {
            // Устаревший план, порченое скрытое поле.
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('marketplace_listings_index');
        }

        $this->addFlash('success', $result->isEmpty()
            ? 'Изменений нет: теги уже совпадают с файлом.'
            : sprintf('Теги обновлены у %d листинг(ов): добавлено %d, снято %d, новых тегов %d.', \count($result->changes), $result->added(), $result->removed(), \count($result->newTags)));

        return $this->redirectToRoute('marketplace_listings_index');
    }

    /**
     * @return list<ListingTagsFileRow>
     */
    private static function rows(string $json): array
    {
        try {
            $data = json_decode($json, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }

        if (!\is_array($data) || !array_is_list($data)) {
            throw new InvalidListingTagsFileException('Данные предпросмотра повреждены. Загрузите файл заново.');
        }

        $rows = [];
        foreach ($data as $row) {
            if (!\is_array($row) || !\is_int($row[0] ?? null) || !\is_string($row[1] ?? null) || !\is_string($row[2] ?? null)) {
                throw new InvalidListingTagsFileException('Данные предпросмотра повреждены. Загрузите файл заново.');
            }
            $rows[] = new ListingTagsFileRow($row[0], $row[1], $row[2]);
        }

        return $rows;
    }
}
