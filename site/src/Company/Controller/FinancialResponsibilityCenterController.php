<?php

declare(strict_types=1);

namespace App\Company\Controller;

use App\Company\Application\ArchiveFinancialResponsibilityCenterAction;
use App\Company\Application\ConfigureFinancialResponsibilityCenterProjectsAction;
use App\Company\Application\CreateFinancialResponsibilityCenterAction;
use App\Company\Application\UpdateFinancialResponsibilityCenterAction;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Company\Form\FinancialResponsibilityCenterProjectsType;
use App\Company\Form\FinancialResponsibilityCenterType;
use App\Company\Repository\FinancialResponsibilityCenterProjectRepository;
use App\Company\Repository\FinancialResponsibilityCenterRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/financial-responsibility-centers')]
final class FinancialResponsibilityCenterController extends AbstractController
{
    #[Route('/', name: 'financial_responsibility_center_index', methods: ['GET'])]
    public function index(
        Request $request,
        ActiveCompanyService $activeCompanyService,
        FinancialResponsibilityCenterRepository $repository,
    ): Response {
        $companyId = (string) $activeCompanyService->getActiveCompany()->getId();
        $showArchived = $request->query->getBoolean('show_archived');

        return $this->render('financial_responsibility_center/index.html.twig', [
            'items' => $repository->findForManagement($companyId, $showArchived),
            'show_archived' => $showArchived,
        ]);
    }

    #[Route('/new', name: 'financial_responsibility_center_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ActiveCompanyService $activeCompanyService,
        CreateFinancialResponsibilityCenterAction $createAction,
    ): Response {
        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $form = $this->createForm(FinancialResponsibilityCenterType::class, [
            'name' => '',
            'sort' => 0,
            'version' => 0,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string, sort: int} $data */
            $data = $form->getData();
            $companyId = (string) $activeCompanyService->getActiveCompany()->getId();
            $createAction($companyId, $data['name'], $data['sort']);
            $this->addFlash('success', 'ЦФО создан. Настройте разрешённые проекты.');

            return $this->redirectToRoute('financial_responsibility_center_index');
        }

        return $this->render('financial_responsibility_center/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'financial_responsibility_center_edit', methods: ['GET', 'POST'])]
    public function edit(
        string $id,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        FinancialResponsibilityCenterRepository $centerRepository,
        FinancialResponsibilityCenterProjectRepository $pairRepository,
        ProjectDirectionRepository $projectRepository,
        UpdateFinancialResponsibilityCenterAction $updateAction,
    ): Response {
        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $company = $activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $center = $centerRepository->findOneByIdAndCompanyId($id, $companyId)
            ?? throw $this->createNotFoundException();

        $form = $this->createForm(FinancialResponsibilityCenterType::class, [
            'name' => $center->getName(),
            'sort' => $center->getSort(),
            'version' => $center->getVersion(),
        ], [
            'system' => $center->isSystem(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string, sort: int, version: numeric-string|int} $data */
            $data = $form->getData();

            try {
                $updateAction($companyId, $id, (int) $data['version'], $data['name'], $data['sort']);
                $this->addFlash('success', 'ЦФО обновлён.');

                return $this->redirectToRoute('financial_responsibility_center_edit', ['id' => $id]);
            } catch (\DomainException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('financial_responsibility_center_edit', ['id' => $id]);
            }
        }

        return $this->renderEdit(
            $center,
            $form,
            $pairRepository,
            $projectRepository,
            $companyId,
            $company,
        );
    }

    #[Route('/{id}/projects', name: 'financial_responsibility_center_projects', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function projects(
        string $id,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        FinancialResponsibilityCenterRepository $centerRepository,
        FinancialResponsibilityCenterProjectRepository $pairRepository,
        ProjectDirectionRepository $projectRepository,
        ConfigureFinancialResponsibilityCenterProjectsAction $configureAction,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $center = $centerRepository->findOneByIdAndCompanyId($id, $companyId)
            ?? throw $this->createNotFoundException();
        $form = $this->createProjectsForm($center, $pairRepository, $projectRepository, $companyId, $company);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{projectDirectionIds: list<string>, version: numeric-string|int} $data */
            $data = $form->getData();

            try {
                $configureAction($companyId, $id, (int) $data['version'], $data['projectDirectionIds']);
                $this->addFlash('success', 'Разрешённые проекты обновлены.');
            } catch (\DomainException $exception) {
                $this->addFlash('danger', $exception->getMessage());
            }
        } else {
            $this->addFlash('danger', 'Не удалось сохранить разрешённые проекты.');
        }

        return $this->redirectToRoute('financial_responsibility_center_edit', ['id' => $id]);
    }

    #[Route('/{id}/archive', name: 'financial_responsibility_center_archive', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function archive(
        string $id,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        FinancialResponsibilityCenterRepository $repository,
        ArchiveFinancialResponsibilityCenterAction $archiveAction,
    ): Response {
        $companyId = (string) $activeCompanyService->getActiveCompany()->getId();
        $center = $repository->findOneByIdAndCompanyId($id, $companyId)
            ?? throw $this->createNotFoundException();

        if ($this->isCsrfTokenValid('archive'.$id, $request->request->get('_token'))) {
            try {
                $archiveAction($companyId, $id, $request->request->getInt('version'));
                $this->addFlash('success', 'ЦФО архивирован.');
            } catch (\DomainException $exception) {
                $this->addFlash('danger', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('financial_responsibility_center_index');
    }

    private function renderEdit(
        FinancialResponsibilityCenter $center,
        FormInterface $form,
        FinancialResponsibilityCenterProjectRepository $pairRepository,
        ProjectDirectionRepository $projectRepository,
        string $companyId,
        Company $company,
    ): Response {
        return $this->render('financial_responsibility_center/edit.html.twig', [
            'item' => $center,
            'form' => $form->createView(),
            'projects_form' => $this->createProjectsForm(
                $center,
                $pairRepository,
                $projectRepository,
                $companyId,
                $company,
            )->createView(),
        ]);
    }

    private function createProjectsForm(
        FinancialResponsibilityCenter $center,
        FinancialResponsibilityCenterProjectRepository $pairRepository,
        ProjectDirectionRepository $projectRepository,
        string $companyId,
        Company $company,
    ): FormInterface {
        $projectLabels = [];
        /** @var ProjectDirection $project */
        foreach ($projectRepository->findTreeByCompany($company) as $project) {
            $path = [];
            for ($node = $project; null !== $node; $node = $node->getParent()) {
                $path[] = $node->getName();
            }

            $projectLabels[(string) $project->getId()] = implode(' → ', array_reverse($path));
        }

        return $this->createForm(FinancialResponsibilityCenterProjectsType::class, [
            'projectDirectionIds' => $pairRepository->findProjectIds($companyId, $center->getId()),
            'version' => $center->getVersion(),
        ], [
            'project_labels' => $projectLabels,
            'action' => $this->generateUrl('financial_responsibility_center_projects', ['id' => $center->getId()]),
        ]);
    }
}
