<?php

declare(strict_types=1);

namespace App\Admin\Controller\Billing;

use App\Billing\Service\Admin\PlanAdminQueryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/billing/plans', name: 'admin_billing_plans_')]
final class PlanAdminController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(PlanAdminQueryService $planAdminQueryService): Response
    {
        return $this->render('admin/billing/plans/list.html.twig', [
            'plans' => $planAdminQueryService->listPlans(),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id, PlanAdminQueryService $planAdminQueryService): Response
    {
        $plan = $planAdminQueryService->getPlan($id);

        if (null === $plan) {
            throw $this->createNotFoundException('План не найден.');
        }

        return $this->render('admin/billing/plans/show.html.twig', [
            'plan' => $plan,
            'planFeatures' => $planAdminQueryService->listPlanFeatures($plan),
        ]);
    }
}
