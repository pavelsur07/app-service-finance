<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api\Admin;

use App\Company\Entity\User;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\Shared\Service\ActiveCompanyService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/marketplace-ads/admin/load-jobs/{jobId}/mark-failed',
    name: 'marketplace_ads_admin_mark_load_job_failed',
    requirements: ['jobId' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
    methods: ['POST'],
)]
#[IsGranted('ROLE_COMPANY_OWNER')]
final class MarkAdLoadJobFailedController extends AbstractController
{
    private const REASON_MAX_LENGTH = 1000;

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly AdLoadJobRepository $adLoadJobRepository,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(string $jobId, Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $body = json_decode($request->getContent(), true) ?? [];
        $rawReason = (string) ($body['reason'] ?? '');
        $reason = trim($rawReason);

        if ('' === $reason) {
            return $this->json(['error' => 'reason required'], 400);
        }

        $reason = mb_substr($reason, 0, self::REASON_MAX_LENGTH);

        $affected = $this->adLoadJobRepository->markFailed($jobId, $companyId, $reason);

        if (0 === $affected) {
            return $this->json(['error' => 'Job not found or already finalized'], 404);
        }

        $user = $this->security->getUser();
        $this->logger->warning('AdLoadJob manually marked as failed', [
            'job_id' => $jobId,
            'company_id' => $companyId,
            'reason' => $reason,
            'user_id' => $user instanceof User ? $user->getId() : null,
        ]);

        return $this->json([
            'jobId' => $jobId,
            'status' => 'failed',
        ]);
    }
}
