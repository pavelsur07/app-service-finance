<?php

declare(strict_types=1);

namespace App\Company\Controller\ProjectDirection;

use App\Company\Application\ProjectDirection\ProjectDirectionExchange;
use App\Company\Application\ProjectDirection\ProjectDirectionImportPreviewStore;
use App\Company\Entity\User;
use App\Company\Form\ProjectDirectionImportType;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/project-directions/import', name: 'project_direction_import', methods: ['GET'])]
#[Route('/project-directions/import', name: 'project_direction_import_preview', methods: ['POST'])]
final class PreviewProjectDirectionsImportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly ProjectDirectionExchange $exchange,
        private readonly ProjectDirectionImportPreviewStore $previews,
    ) {
    }

    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $form = $this->createForm(ProjectDirectionImportType::class);
        $form->handleRequest($request);
        $view = ['form' => $form, 'preview' => null, 'previewId' => null];
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('project_direction/import.html.twig', $view);
        }

        $file = $form->get('file')->getData();
        if (!$file instanceof UploadedFile) {
            throw new \LogicException('Для корректной формы требуется загруженный файл.');
        }
        try {
            $json = $file->getContent();
        } catch (FileException) {
            $form->get('file')->addError(new FormError('Не удалось прочитать файл.'));

            return $this->render('project_direction/import.html.twig', $view);
        }

        $preview = $this->exchange->preview($companyId, $json);
        $view['preview'] = $preview;
        if ([] !== $preview->errors) {
            return $this->render('project_direction/import.html.twig', $view, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $session = $request->getSession();
        $session->start();
        $json = json_encode($this->exchange->document($companyId, $preview->projects), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $view['previewId'] = $this->previews->store($session->getId(), (string) $user->getId(), $companyId, $json, $preview->revision);
        if (null === $view['previewId']) {
            $form->addError(new FormError('Не удалось сохранить предпросмотр. Попробуйте загрузить файл ещё раз.'));

            return $this->render('project_direction/import.html.twig', $view, new Response(status: Response::HTTP_SERVICE_UNAVAILABLE));
        }

        return $this->render('project_direction/import.html.twig', $view);
    }
}
