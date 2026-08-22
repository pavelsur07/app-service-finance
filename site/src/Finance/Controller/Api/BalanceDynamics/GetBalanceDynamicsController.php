<?php

declare(strict_types=1);

namespace App\Finance\Controller\Api\BalanceDynamics;

use App\Finance\Application\Service\FinanceBalanceDynamicsProvider;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class GetBalanceDynamicsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly FinanceBalanceDynamicsProvider $provider,
    ) {
    }

    #[Route(
        '/api/finance/dashboard/balance-dynamics',
        name: 'api_finance_dashboard_balance_dynamics',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $query = BalanceDynamicsRequest::fromRequest($request);
        } catch (\InvalidArgumentException $exception) {
            return $this->json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => $exception->getMessage(),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->provider->build(
            $this->activeCompanyService->getActiveCompany(),
            $query->currency,
            $query->periodDays,
            new \DateTimeImmutable('today'),
        );
        $response = new BalanceDynamicsResponse(
            periodDays: $result['periodDays'],
            from: $result['from'],
            to: $result['to'],
            currency: $result['currency'],
            minimumBalance: $result['minimumBalance'],
            points: $result['points'],
        );

        return $this->json($response->toArray());
    }
}
