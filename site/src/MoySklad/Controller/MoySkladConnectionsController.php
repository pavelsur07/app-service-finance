<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\MoySklad\Application\Action\CreateMoySkladConnectionAction;
use App\MoySklad\Application\Action\DeleteMoySkladConnectionAction;
use App\MoySklad\Application\Action\UpdateMoySkladConnectionAction;
use App\MoySklad\Application\Command\CreateMoySkladConnectionCommand;
use App\MoySklad\Application\Command\DeleteMoySkladConnectionCommand;
use App\MoySklad\Application\Command\UpdateMoySkladConnectionCommand;
use App\MoySklad\Form\MoySkladConnectionType;
use App\MoySklad\Infrastructure\Query\MoySkladConnectionsQuery;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/moy-sklad/connections')]
#[IsGranted('ROLE_USER')]
final class MoySkladConnectionsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MoySkladConnectionsQuery $connectionsQuery,
    ) {
    }

    #[Route('', name: 'moysklad_connections_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderPage();
    }

    #[Route('/create', name: 'moysklad_connections_create', methods: ['GET', 'POST'])]
    public function create(Request $request, CreateMoySkladConnectionAction $action): Response
    {
        $form = $this->createForm(MoySkladConnectionType::class, ['isActive' => true]);
        $form->handleRequest($request);

        if ($request->isMethod('GET')) {
            return $this->redirectToRoute('moysklad_connections_index');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

            try {
                $action(new CreateMoySkladConnectionCommand(
                    companyId: $companyId,
                    name: (string) $data['name'],
                    baseUrl: (string) $data['baseUrl'],
                    login: $data['login'] !== '' ? $data['login'] : null,
                    accessToken: $data['accessToken'] !== '' ? $data['accessToken'] : null,
                    refreshToken: $data['refreshToken'] !== '' ? $data['refreshToken'] : null,
                    tokenExpiresAt: $data['tokenExpiresAt'],
                    isActive: (bool) ($data['isActive'] ?? false),
                ));

                $this->addFlash('success', 'Подключение создано.');

                return $this->redirectToRoute('moysklad_connections_index');
            } catch (\DomainException|\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->renderPage(createForm: $form);
    }

    #[Route('/{id}/edit', name: 'moysklad_connections_edit', methods: ['GET', 'POST'])]
    public function edit(
        string $id,
        Request $request,
        MoySkladConnectionWriteRepository $repository,
        UpdateMoySkladConnectionAction $action,
    ): Response {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $connection = $repository->findByIdAndCompanyId($id, $companyId);

        if ($connection === null) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(MoySkladConnectionType::class, [
            'name' => $connection->getName(),
            'baseUrl' => $connection->getBaseUrl(),
            'login' => $connection->getLogin(),
            'accessToken' => $connection->getAccessToken(),
            'refreshToken' => $connection->getRefreshToken(),
            'tokenExpiresAt' => $connection->getTokenExpiresAt(),
            'isActive' => $connection->isActive(),
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            try {
                $action(new UpdateMoySkladConnectionCommand(
                    id: $connection->getId(),
                    companyId: $companyId,
                    name: (string) $data['name'],
                    baseUrl: (string) $data['baseUrl'],
                    login: $data['login'] !== '' ? $data['login'] : null,
                    accessToken: $data['accessToken'] !== '' ? $data['accessToken'] : null,
                    refreshToken: $data['refreshToken'] !== '' ? $data['refreshToken'] : null,
                    tokenExpiresAt: $data['tokenExpiresAt'],
                    isActive: (bool) ($data['isActive'] ?? false),
                ));

                $this->addFlash('success', 'Подключение обновлено.');

                return $this->redirectToRoute('moysklad_connections_index');
            } catch (\DomainException|\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->renderPage(editForm: $form, isEdit: true, editId: $connection->getId());
    }

    #[Route('/{id}/delete', name: 'moysklad_connections_delete', methods: ['POST'])]
    public function delete(string $id, Request $request, DeleteMoySkladConnectionAction $action): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

        try {
            $action(new DeleteMoySkladConnectionCommand($id, $companyId));
            $this->addFlash('success', 'Подключение удалено.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('moysklad_connections_index');
    }

    private function renderPage(?FormInterface $createForm = null, ?FormInterface $editForm = null, bool $isEdit = false, ?string $editId = null): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

        if ($createForm === null) {
            $createForm = $this->createForm(MoySkladConnectionType::class, ['isActive' => true]);
        }

        return $this->render('moy_sklad/connections/index.html.twig', [
            'connections' => $this->connectionsQuery->allByCompanyId($companyId),
            'createForm' => $createForm->createView(),
            'editForm' => $editForm?->createView(),
            'isEdit' => $isEdit,
            'editId' => $editId,
        ]);
    }
}
