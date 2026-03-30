<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\MarketplaceAnalytics\Api\Request\RemapCostMappingRequest;
use App\MarketplaceAnalytics\Api\Response\CostMappingResponse;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Facade\MarketplaceAnalyticsFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_USER')]
final class CostMappingRemapController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceAnalyticsFacade $facade,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route(
        '/api/marketplace-analytics/cost-mappings/{id}/remap',
        name: 'marketplace_analytics_api_cost_mapping_remap',
        methods: ['PATCH'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $dto = $this->serializer->deserialize(
            $request->getContent(),
            RemapCostMappingRequest::class,
            'json',
        );

        $violations = $this->validator->validate($dto);
        if ($violations->count() > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = [
                    'field' => $violation->getPropertyPath(),
                    'message' => $violation->getMessage(),
                ];
            }

            return $this->json(
                ['type' => 'VALIDATION_ERROR', 'message' => 'Ошибка валидации', 'errors' => $errors],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $type = UnitEconomyCostType::from($dto->unitEconomyCostType);
            $mapping = $this->facade->remapCostMapping($company->getId(), $id, $type);
        } catch (\DomainException $e) {
            return $this->json(
                ['type' => 'DOMAIN_ERROR', 'message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->json(CostMappingResponse::fromEntity($mapping)->toArray());
    }
}
