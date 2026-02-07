<?php

namespace App\Deals\Controller;

use App\Company\Entity\User;
use App\Deals\DTO\ChargeTypeFormData;
use App\Deals\Exception\AccessDenied as DealAccessDenied;
use App\Deals\Exception\ValidationFailed;
use App\Deals\Form\ChargeTypeType;
use App\Deals\Repository\ChargeTypeRepository;
use App\Deals\Service\ChargeTypeManager;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/deals/charge-types')]
final class ChargeTypeController extends AbstractController
{
    public function __construct(private readonly ActiveCompanyService $companyService)
    {
    }

    #[Route('', name: 'deal_charge_type_index', methods: ['GET'])]
    public function index(Request $request, ChargeTypeRepository $chargeTypeRepository): Response
    {
        $company = $this->companyService->getActiveCompany();
        $isActive = $this->parseIsActive($request->query->get('isActive'));
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, (int) $request->query->get('limit', 20));

        $pager = $chargeTypeRepository->findListForCompany($company, $isActive, $page, $limit);
        $chargeTypes = iterator_to_array($pager->getCurrentPageResults());

        return $this->render('deals/charge_types/index.html.twig', [
            'chargeTypes' => $chargeTypes,
            'filters' => [
                'isActive' => $request->query->get('isActive'),
                'page' => $page,
                'limit' => $limit,
            ],
            'pager' => $pager,
        ]);
    }

    #[Route('/new', name: 'deal_charge_type_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ChargeTypeManager $chargeTypeManager): Response
    {
        $company = $this->companyService->getActiveCompany();
        $formData = new ChargeTypeFormData();
        $form = $this->createForm(ChargeTypeType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUserOrFail();

            try {
                $chargeTypeManager->create($formData, $user, $company);
            } catch (DealAccessDenied $exception) {
                return $this->renderErrorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
            } catch (ValidationFailed $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->render('deals/charge_types/new.html.twig', [
                    'form' => $form->createView(),
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', 'Тип начислений создан.');

            return $this->redirectToRoute('deal_charge_type_index');
        }

        return $this->render('deals/charge_types/new.html.twig', [
            'form' => $form->createView(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'deal_charge_type_edit', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        string $id,
        ChargeTypeRepository $chargeTypeRepository,
        ChargeTypeManager $chargeTypeManager,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        $chargeType = $chargeTypeRepository->findOneByIdForCompany($id, $company);
        if (!$chargeType) {
            return $this->renderErrorResponse('Тип начислений не найден.', Response::HTTP_NOT_FOUND);
        }

        $formData = ChargeTypeFormData::fromEntity($chargeType);
        $form = $this->createForm(ChargeTypeType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUserOrFail();

            try {
                $chargeTypeManager->update($chargeType->getId(), $formData, $user, $company);
            } catch (DealAccessDenied $exception) {
                return $this->renderErrorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
            } catch (ValidationFailed $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->render('deals/charge_types/edit.html.twig', [
                    'form' => $form->createView(),
                    'chargeType' => $chargeType,
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->addFlash('success', 'Тип начислений обновлён.');

            return $this->redirectToRoute('deal_charge_type_index');
        }

        return $this->render('deals/charge_types/edit.html.twig', [
            'form' => $form->createView(),
            'chargeType' => $chargeType,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/toggle', name: 'deal_charge_type_toggle', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function toggle(
        Request $request,
        string $id,
        ChargeTypeRepository $chargeTypeRepository,
        ChargeTypeManager $chargeTypeManager,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        $chargeType = $chargeTypeRepository->findOneByIdForCompany($id, $company);
        if (!$chargeType) {
            return $this->renderErrorResponse('Тип начислений не найден.', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('deal_charge_type_toggle'.$id, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $user = $this->getUserOrFail();

        try {
            $chargeTypeManager->toggle($id, $user, $company);
        } catch (DealAccessDenied $exception) {
            return $this->renderErrorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (ValidationFailed $exception) {
            return $this->renderErrorResponse($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Статус типа начислений обновлён.');

        return $this->redirectToRoute('deal_charge_type_index');
    }

    private function getUserOrFail(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }

    private function parseIsActive(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ((string) $value === '1') {
            return true;
        }

        if ((string) $value === '0') {
            return false;
        }

        return null;
    }

    private function renderErrorResponse(string $message, int $status): Response
    {
        $this->addFlash('danger', $message);

        return $this->render('deals/error.html.twig', [
            'message' => $message,
            'status' => $status,
        ], new Response(status: $status));
    }
}
