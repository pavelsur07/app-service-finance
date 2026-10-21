<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Application;

use App\Company\Entity\Company;
use App\Company\Enum\BusinessProfile;
use App\Finance\Application\Action\ApplyDefaultPLTemplateAction;
use App\Finance\Application\Action\EnsureDefaultPLTreeAction;
use App\Finance\Application\DTO\ImportPLCategoryTreeResult;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLTemplateApplicationSource;
use App\Finance\Exception\PLTemplateProfileMismatchException;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Builders\Finance\PLTemplateApplicationBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApplyDefaultPLTemplateActionTest extends IntegrationTestCase
{
    public function testOnboardingRecordsOriginOnceAndEmptyApplicationIsNotRecorded(): void
    {
        $company = $this->company(BusinessProfile::UNIVERSAL);

        self::assertSame(44, (self::getContainer()->get(EnsureDefaultPLTreeAction::class))((string) $company->getId()));
        $this->apply($company, dryRun: false, source: PLTemplateApplicationSource::SCREEN);

        self::assertSame([['UNIVERSAL', 2, 44, 'onboarding']], $this->records($company), 'повтор ничего не создал — записи нет');
    }

    public function testDryRunWritesNothing(): void
    {
        $company = $this->company(BusinessProfile::MARKETPLACE);

        self::assertCount(72, $this->apply($company, dryRun: true)->created);

        self::assertSame([], $this->records($company));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pl_categories WHERE company_id = ?', [(string) $company->getId()]));
    }

    #[DataProvider('modes')]
    public function testTemplateOfAnotherProfileIsRefusedForTreeWithOrigin(bool $dryRun): void
    {
        $company = $this->company(BusinessProfile::UNIVERSAL);
        $this->em->persist(PLTemplateApplicationBuilder::anApplication()->forCompany((string) $company->getId())->withProfile(BusinessProfile::MARKETPLACE)->build());
        $this->em->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Выручка')->withCode('REV_TOTAL')->withType(PLCategoryType::KPI)->build());
        $this->em->flush();

        try {
            $this->apply($company, $dryRun);
            self::fail('Шаблон другого профиля не должен применяться');
        } catch (PLTemplateProfileMismatchException $e) {
            self::assertStringContainsString('создано шаблоном «Маркетплейсы и e-com»; шаблон «Базовый»', $e->getMessage());
        }

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pl_categories WHERE company_id = ?', [(string) $company->getId()]));
        self::assertCount(1, $this->records($company));
    }

    /**
     * Регрессия (ревью): пустое дерево смешивать не с чем — шаблон нового профиля его собирает и
     * становится происхождением; дозаполнение онбординга на такой компании не падает.
     */
    public function testEmptyTreeIsBuiltByNewProfileAndBecomesOrigin(): void
    {
        $company = $this->company(BusinessProfile::UNIVERSAL);
        $this->em->persist(PLTemplateApplicationBuilder::anApplication()->forCompany((string) $company->getId())->withProfile(BusinessProfile::MARKETPLACE)->appliedAt(new \DateTimeImmutable('2026-10-01 10:00'))->build());
        $this->em->flush();

        self::assertSame(44, (self::getContainer()->get(EnsureDefaultPLTreeAction::class))((string) $company->getId(), dryRun: true));
        self::assertSame(44, (self::getContainer()->get(EnsureDefaultPLTreeAction::class))((string) $company->getId()));

        self::assertSame(['MARKETPLACE', 'UNIVERSAL'], array_map(static fn (array $r): string => $r[0], $this->records($company)));
        self::assertCount(0, $this->apply($company, dryRun: true)->created, 'дальше — дерево «Базового»');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'предпросмотр' => [true];
        yield 'применение' => [false];
    }

    public function testOriginIsTheLatestApplication(): void
    {
        $company = $this->company(BusinessProfile::MARKETPLACE);
        $id = (string) $company->getId();
        $this->em->persist(PLTemplateApplicationBuilder::anApplication()->forCompany($id)->withProfile(BusinessProfile::UNIVERSAL)->appliedAt(new \DateTimeImmutable('2026-10-01 10:00'))->build());
        $this->em->persist(PLTemplateApplicationBuilder::anApplication()->forCompany($id)->withProfile(BusinessProfile::MARKETPLACE)->appliedAt(new \DateTimeImmutable('2026-10-22 10:00'))->build());
        $this->em->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Выручка')->withCode('REV_TOTAL')->withType(PLCategoryType::KPI)->build());
        $this->em->flush();

        self::assertCount(71, $this->apply($company, dryRun: true)->created);
    }

    public function testTreeWithoutOriginIsNotCheckedByProfile(): void
    {
        $company = $this->company(BusinessProfile::UNIVERSAL);

        self::assertCount(44, $this->apply($company, dryRun: false, source: PLTemplateApplicationSource::COMMAND)->created);
        self::assertSame([['UNIVERSAL', 2, 44, 'command']], $this->records($company));
    }

    private function apply(Company $company, bool $dryRun, PLTemplateApplicationSource $source = PLTemplateApplicationSource::SCREEN): ImportPLCategoryTreeResult
    {
        return (self::getContainer()->get(ApplyDefaultPLTemplateAction::class))((string) $company->getId(), $dryRun, $source);
    }

    private function company(BusinessProfile $profile): Company
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $company->setBusinessProfile($profile);
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    /**
     * @return list<array{string, int, int, string}>
     */
    private function records(Company $company): array
    {
        return array_map(
            static fn (array $r): array => [(string) $r['profile'], (int) $r['template_version'], (int) $r['created_count'], (string) $r['source']],
            $this->connection->fetchAllAssociative('SELECT profile, template_version, created_count, source FROM pl_template_applications WHERE company_id = ? ORDER BY applied_at', [(string) $company->getId()]),
        );
    }
}
