<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds;

use App\Company\Entity\Company;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class AdRawDocumentRepositoryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000002';
    private const OWNER_ID = '22222222-2222-2222-2222-000000000001';
    private const OTHER_OWNER_ID = '22222222-2222-2222-2222-000000000002';

    private AdRawDocumentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(AdRawDocumentRepository::class);
    }

    public function testMarkFailedWithReasonFirstCallReturnOneAndPersistsFailed(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');

        $doc = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();

        $this->repository->save($doc);
        $this->em->flush();
        $this->em->clear();

        $affected = $this->repository->markFailedWithReason(
            $doc->getId(),
            self::COMPANY_ID,
            'Парсинг упал: неизвестный формат',
        );

        self::assertSame(1, $affected);

        $conn = $this->em->getConnection();
        $row = $conn->fetchAssociative(
            'SELECT status, processing_error FROM marketplace_ad_raw_documents WHERE id = :id',
            ['id' => $doc->getId()],
        );
        self::assertSame(AdRawDocumentStatus::FAILED->value, $row['status']);
        self::assertSame('Парсинг упал: неизвестный формат', $row['processing_error']);
    }

    public function testMarkFailedWithReasonIsIdempotentOnAlreadyFailed(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');

        $doc = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();

        $this->repository->save($doc);
        $this->em->flush();

        // Первый вызов — ставит FAILED
        $this->repository->markFailedWithReason($doc->getId(), self::COMPANY_ID, 'Первая причина');

        // Повторный вызов — 0 affected, reason не меняется
        $affected = $this->repository->markFailedWithReason($doc->getId(), self::COMPANY_ID, 'Вторая причина');

        self::assertSame(0, $affected);

        $conn = $this->em->getConnection();
        $reason = $conn->fetchOne(
            'SELECT processing_error FROM marketplace_ad_raw_documents WHERE id = :id',
            ['id' => $doc->getId()],
        );
        self::assertSame('Первая причина', $reason);
    }

    public function testMarkFailedWithReasonIgnoresWrongCompanyId(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->seedCompany(self::OTHER_COMPANY_ID, self::OTHER_OWNER_ID, 'b@example.test');

        $doc = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();

        $this->repository->save($doc);
        $this->em->flush();

        // Чужой companyId → 0 affected, документ не изменился
        $affected = $this->repository->markFailedWithReason(
            $doc->getId(),
            self::OTHER_COMPANY_ID,
            'IDOR attempt',
        );

        self::assertSame(0, $affected);

        $conn = $this->em->getConnection();
        $status = $conn->fetchOne(
            'SELECT status FROM marketplace_ad_raw_documents WHERE id = :id',
            ['id' => $doc->getId()],
        );
        self::assertSame(AdRawDocumentStatus::DRAFT->value, $status);
    }

    private function seedCompany(string $companyId, string $ownerId, string $email): Company
    {
        $owner = UserBuilder::aUser()
            ->withId($ownerId)
            ->withEmail($email)
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }
}
