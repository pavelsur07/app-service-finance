<?php

declare(strict_types=1);

namespace App\Inventory\Controller;

use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Repository\InventoryRawSnapshotRepository;
use App\Inventory\Repository\InventorySnapshotSessionRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Webmozart\Assert\Assert;

#[IsGranted('ROLE_USER')]
final class SnapshotRawJsonController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly InventorySnapshotSessionRepository $sessionRepository,
        private readonly InventoryRawSnapshotRepository $rawSnapshotRepository,
    ) {
    }

    #[Route('/inventory/snapshots/{id}/json', name: 'inventory_snapshots_json', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        Assert::uuid($id);

        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $session = $this->sessionRepository->findByIdAndCompany($id, $companyId);
        if ($session === null) {
            throw $this->createNotFoundException('Inventory snapshot session not found.');
        }

        $rawSnapshots = $this->rawSnapshotRepository->findBySessionAndCompanyOrdered($session->getId(), $companyId);

        $payload = [
            'snapshotSession' => [
                'id' => $session->getId(),
                'companyId' => $session->getCompanyId(),
                'source' => $session->getSource()->value,
                'status' => $session->getStatus()->value,
                'triggerType' => $session->getTriggerType()->value,
                'createdAt' => $session->getCreatedAt()->format(DATE_ATOM),
                'startedAt' => $session->getStartedAt()?->format(DATE_ATOM),
                'completedAt' => $session->getCompletedAt()?->format(DATE_ATOM),
                'receivedPages' => $session->getReceivedPages(),
                'expectedPages' => $session->getExpectedPages(),
                'errorMessage' => $session->getErrorMessage(),
                'correlationId' => $session->getCorrelationId(),
            ],
            'rawSnapshots' => array_map(static fn (InventoryRawSnapshot $snapshot): array => [
                'id' => $snapshot->getId(),
                'pageNumber' => $snapshot->getPageNumber(),
                'sourceEndpoint' => $snapshot->getSourceEndpoint(),
                'requestParams' => $snapshot->getRequestParams(),
                'responseStatus' => $snapshot->getResponseStatus(),
                'fetchedAt' => $snapshot->getFetchedAt()->format(DATE_ATOM),
                'fetchDurationMs' => $snapshot->getFetchDurationMs(),
                'responseBody' => $snapshot->getResponseBody(),
            ], $rawSnapshots),
        ];

        if ($rawSnapshots === []) {
            $payload['message'] = 'No raw snapshots saved for this session.';
        }

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
