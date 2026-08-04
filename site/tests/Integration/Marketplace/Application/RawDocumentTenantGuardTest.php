<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Application\ProcessOzonRealizationAction;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Регрессионные тесты H4: Actions обработки raw-документов обязаны отвергать
 * документ чужой компании, даже если вызывающий код не проверил принадлежность.
 */
final class RawDocumentTenantGuardTest extends IntegrationTestCase
{
    public function testProcessOzonRealizationActionRejectsForeignCompanyDocument(): void
    {
        [$ownerCompany, $foreignCompany] = $this->seedTwoCompaniesWithDocument('realization');

        $action = self::getContainer()->get(ProcessOzonRealizationAction::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Raw document does not belong to the given company.');

        // companyId владельца, но rawDocId чужой компании
        ($action)((string) $ownerCompany->getId(), (string) $this->documentId);
    }

    public function testProcessMarketplaceRawDocumentActionRejectsForeignCompanyDocument(): void
    {
        [$ownerCompany, $foreignCompany] = $this->seedTwoCompaniesWithDocument('sales_report');

        $action = self::getContainer()->get(ProcessMarketplaceRawDocumentAction::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Raw document does not belong to the given company.');

        ($action)(new ProcessMarketplaceRawDocumentCommand(
            companyId: (string) $ownerCompany->getId(),
            rawDocId: (string) $this->documentId,
            kind: 'sales',
        ));
    }

    public function testProcessOzonRealizationActionAcceptsOwnDocument(): void
    {
        [$ownerCompany] = $this->seedTwoCompaniesWithDocument('realization', true);

        $action = self::getContainer()->get(ProcessOzonRealizationAction::class);

        // Пустой документ (без rows) — успешный no-op, исключения быть не должно
        $result = ($action)((string) $this->documentCompanyId, (string) $this->documentId);

        self::assertSame(['created' => 0, 'updated' => 0, 'skipped' => 0], $result);
    }

    private string $documentId;
    private string $documentCompanyId;

    /**
     * Создаёт две компании и raw-документ, принадлежащий ВТОРОЙ (чужой) компании.
     * Возвращает [компания-«владелец» вызова, чужая компания].
     * При $ownDocument=true документ принадлежит первой компании.
     */
    private function seedTwoCompaniesWithDocument(string $documentType, bool $ownDocument = false): array
    {
        $userA = UserBuilder::aUser()->withIndex(1)->build();
        $companyA = CompanyBuilder::aCompany()->withIndex(1)->withOwner($userA)->build();
        $userB = UserBuilder::aUser()->withIndex(2)->build();
        $companyB = CompanyBuilder::aCompany()->withIndex(2)->withOwner($userB)->build();

        $docCompany = $ownDocument ? $companyA : $companyB;
        $doc = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($docCompany)
            ->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType($documentType)
            ->withPeriod(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'))
            ->build();

        $this->em->persist($userA);
        $this->em->persist($companyA);
        $this->em->persist($userB);
        $this->em->persist($companyB);
        $this->em->persist($doc);
        $this->em->flush();

        $this->documentId = (string) $doc->getId();
        $this->documentCompanyId = (string) $docCompany->getId();

        return [$companyA, $companyB];
    }
}
