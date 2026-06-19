<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Repository;

use App\Ingestion\Entity\NormalizationIssue;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Repository\NormalizationIssueRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class NormalizationIssueRepositoryTest extends IntegrationTestCase
{
    public function testOpenIssueQueriesUseCompanyIdAndResolvedFilter(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();
        $rawRecordId = Uuid::uuid7()->toString();
        $openA = new NormalizationIssue($companyA, $rawRecordId, null, NormalizationIssueKind::SUM_MISMATCH, ['a' => 1]);
        $resolvedA = new NormalizationIssue($companyA, $rawRecordId, null, NormalizationIssueKind::MAPPER_FAILURE, ['a' => 2]);
        $resolvedA->markResolved();
        $openB = new NormalizationIssue($companyB, $rawRecordId, null, NormalizationIssueKind::SUM_MISMATCH, ['b' => 1]);

        foreach ([$openA, $resolvedA, $openB] as $issue) {
            $this->em->persist($issue);
        }
        $this->em->flush();
        $this->em->clear();

        /** @var NormalizationIssueRepository $repository */
        $repository = self::getContainer()->get(NormalizationIssueRepository::class);

        self::assertSame([$openA->getId()], array_map(
            static fn (NormalizationIssue $issue): string => $issue->getId(),
            $repository->findOpenByRawRecord($companyA, $rawRecordId),
        ));
        self::assertSame(1, $repository->countOpenForCompany($companyA));
        self::assertSame(1, $repository->countOpenForCompany($companyB));
    }
}
