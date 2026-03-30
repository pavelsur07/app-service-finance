<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\MarketplaceAnalytics\Api\Request\RecalculateSnapshotsRequest;
use App\MarketplaceAnalytics\Api\Response\RecalculateJobResponse;
use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
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
final class SnapshotRecalculateController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceAnalyticsFacade $facade,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route(
        '/api/marketplace-analytics/snapshots/recalculate',
        name: 'marketplace_analytics_api_snapshot_recalculate',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $dto = $this->serializer->deserialize(
            $request->getContent(),
            RecalculateSnapshotsRequest::class,
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
            $period = AnalysisPeriod::custom(
                new \DateTimeImmutable($dto->dateFrom),
                new \DateTimeImmutable($dto->dateTo),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(
                ['type' => 'BAD_REQUEST', 'message' => $e->getMessage()],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $jobId = $this->facade->requestRecalc($company->getId(), $period);
        } catch (\DomainException $e) {
            return $this->json(
                ['type' => 'DOMAIN_ERROR', 'message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $response = new RecalculateJobResponse(
            jobId: $jobId,
            status: 'pending',
            message: 'Пересчёт запущен',
            marketplace: $dto->marketplace,
            dateFrom: $dto->dateFrom,
            dateTo: $dto->dateTo,
        );

        return $this->json($response->toArray(), Response::HTTP_ACCEPTED);
    }
}
