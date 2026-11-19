<?php

declare(strict_types=1);

namespace App\Company\Controller\ProjectDirection;

use App\Company\Application\ProjectDirection\ImportProjectDirectionsAction;
use App\Company\Application\ProjectDirection\ProjectDirectionPreviewStore;
use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/project-directions/import/confirm', name: 'project_direction_import_confirm', methods: ['POST'])]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class ConfirmProjectDirectionsImportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly ProjectDirectionPreviewStore $previews,
        private readonly ImportProjectDirectionsAction $import,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $company = $this->activeCompany->getActiveCompany();
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('project_direction_import_confirm', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $draft = $this->previews->take(
            $request->getSession()->getId(),
            (string) $user->getId(),
            (string) $company->getId(),
            $request->request->getString('previewId'),
        );
        if (!\is_array($draft)) {
            $this->addFlash('danger', 'Предпросмотр недоступен или истёк. Загрузите файл ещё раз.');

            return $this->redirectToRoute('project_direction_import');
        }

        try {
            $result = ($this->import)($company, $draft['json'], $draft['revision']);
        } catch (UniqueConstraintViolationException) {
            // Глобально уникальный UUID мог быть занят другой компанией во время записи.
            $this->addFlash('danger', 'Обнаружен конфликт при записи проектов. Загрузите файл для нового предпросмотра.');

            return $this->redirectToRoute('project_direction_import');
        }

        if ([] !== $result->errors) {
            foreach ($result->errors as $error) {
                $this->addFlash('danger', $error);
            }

            return $this->redirectToRoute('project_direction_import');
        }
        $this->addFlash('success', sprintf(
            'Загрузка завершена. Новых проектов: %d. Изменённых проектов: %d. Без изменений: %d.',
            $result->created,
            $result->updated,
            $result->unchanged,
        ));

        return $this->redirectToRoute('project_direction_index');
    }
}
