<?php

declare(strict_types=1);

namespace App\Company\Controller\ProjectDirection;

use App\Company\Application\ProjectDirection\CopyProjectDirectionsAction;
use App\Company\Application\ProjectDirection\ProjectDirectionCopyDraft;
use App\Company\Application\ProjectDirection\ProjectDirectionCopySources;
use App\Company\Application\ProjectDirection\ProjectDirectionPreviewStore;
use App\Company\Entity\User;
use App\Company\Form\ProjectDirectionCopyDecisions;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/project-directions/copy/confirm', name: 'project_direction_copy_confirm', methods: ['POST'])]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class ConfirmProjectDirectionsCopyController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly ProjectDirectionCopySources $sources,
        private readonly ProjectDirectionPreviewStore $previews,
        private readonly CopyProjectDirectionsAction $copy,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $targetId = (string) $this->activeCompany->getActiveCompany()->getId();
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('project_direction_copy_confirm', $request->request->getString('confirmToken'))) {
            throw $this->createAccessDeniedException();
        }

        $sourceId = strtolower($request->request->getString('sourceId'));
        if (!isset($this->sources->forUser($user, $targetId)[$sourceId])) {
            throw $this->createAccessDeniedException();
        }

        $decisions = ProjectDirectionCopyDecisions::read($request);
        $draft = $this->previews->take(
            $request->getSession()->getId(),
            (string) $user->getId(),
            ProjectDirectionCopyDraft::scope($sourceId, $targetId),
            $request->request->getString('previewId'),
        );
        if (!$draft instanceof ProjectDirectionCopyDraft) {
            $this->addFlash('danger', 'Предпросмотр недоступен или истёк. Сформируйте новый предпросмотр.');

            return $this->redirectToRoute('project_direction_copy');
        }
        if ($decisions !== $draft->decisions) {
            $this->addFlash('warning', 'Решения по кодам изменились. Обновите предпросмотр перед копированием.');

            return $this->redirectToRoute('project_direction_copy');
        }

        try {
            $result = $this->copy->confirm($sourceId, $targetId, $draft->sourceRevision, $draft->targetRevision, $draft->decisions);
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('danger', 'Обнаружен конфликт при записи проектов. Сформируйте новый предпросмотр.');

            return $this->redirectToRoute('project_direction_copy');
        }

        if ([] !== $result->errors) {
            foreach ($result->errors as $error) {
                $this->addFlash('danger', $error);
            }

            return $this->redirectToRoute('project_direction_copy');
        }

        $this->addFlash('success', sprintf(
            'Копирование завершено. Создано проектов — %d. Связано с существующими — %d.',
            \count($result->creations),
            \count($result->mappings) - \count($result->creations),
        ));

        return $this->redirectToRoute('project_direction_index');
    }
}
