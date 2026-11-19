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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/project-directions/copy', name: 'project_direction_copy', methods: ['GET'])]
#[Route('/project-directions/copy/preview', name: 'project_direction_copy_preview', methods: ['POST'])]
final class PreviewProjectDirectionsCopyController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly ProjectDirectionCopySources $sources,
        private readonly CopyProjectDirectionsAction $copy,
        private readonly ProjectDirectionPreviewStore $previews,
    ) {
    }

    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): Response
    {
        $target = $this->activeCompany->getActiveCompany();
        $targetId = (string) $target->getId();
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST') && !$this->isCsrfTokenValid('project_direction_copy_preview', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $sources = $this->sources->forUser($user, $targetId);
        $view = ['targetName' => $target->getName(), 'sources' => $sources, 'sourceId' => '', 'preview' => null, 'previewId' => null];
        if (!$request->isMethod('POST')) {
            return $this->render('project_direction/copy.html.twig', $view);
        }

        $sourceId = strtolower($request->request->getString('sourceId'));
        if (!isset($sources[$sourceId])) {
            throw $this->createAccessDeniedException();
        }

        $decisions = ProjectDirectionCopyDecisions::read($request);
        $preview = $this->copy->preview($sourceId, $targetId, $decisions);
        $view['sourceId'] = $sourceId;
        $view['preview'] = $preview;
        if ([] !== $preview->errors) {
            return $this->render('project_direction/copy.html.twig', $view, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if ([] === $preview->mappings) {
            return $this->render('project_direction/copy.html.twig', $view);
        }

        $availableDecisions = [];
        foreach ($preview->rows as $row) {
            if (null !== $row['conflict'] && isset($decisions[$row['id']])) {
                $availableDecisions[$row['id']] = $decisions[$row['id']];
            }
        }
        ksort($availableDecisions);

        $session = $request->getSession();
        $session->start();
        $view['previewId'] = $this->previews->store(
            $session->getId(),
            (string) $user->getId(),
            ProjectDirectionCopyDraft::scope($sourceId, $targetId),
            new ProjectDirectionCopyDraft($preview->sourceRevision, $preview->targetRevision, $availableDecisions),
        );
        if (null === $view['previewId']) {
            $this->addFlash('danger', 'Не удалось сохранить предпросмотр. Сформируйте его ещё раз.');

            return $this->redirectToRoute('project_direction_copy');
        }

        return $this->render('project_direction/copy.html.twig', $view);
    }
}
