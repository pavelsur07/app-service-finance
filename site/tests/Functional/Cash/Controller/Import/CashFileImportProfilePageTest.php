<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller\Import;

use App\Cash\Entity\Import\CashFileImportProfile;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

/** Smoke-тест списка профилей импорта: рендер своей компании и кнопка удаления через диалог подтверждения. */
final class CashFileImportProfilePageTest extends WebTestCaseBase
{
    public function testIndexListsOwnProfilesWithConfirmTrigger(): void
    {
        $client = static::createClient();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('import-profiles-owner@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $stranger = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('import-profiles-stranger@example.test')->build();
        $otherCompany = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($stranger)->build();
        $own = new CashFileImportProfile(Uuid::uuid7()->toString(), $company, 'Выписка Сбера');
        $foreign = new CashFileImportProfile(Uuid::uuid7()->toString(), $otherCompany, 'Чужой профиль');
        foreach ([$owner, $company, $stranger, $otherCompany, $own, $foreign] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
        $crawler = $client->request('GET', '/cash/import/file/profiles');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Выписка Сбера');
        self::assertSelectorTextNotContains('table', 'Чужой профиль');
        self::assertCount(1, $crawler->filter('#import-profile-confirm[data-confirm-modal]'));
        $trigger = $crawler->filter(sprintf('[data-confirm-action="/cash/import/file/profiles/%s/delete"]', $own->getId()));
        self::assertCount(1, $trigger);
        self::assertSame('Удалить профиль?', $trigger->attr('data-confirm-title'));
        self::assertNotSame('', (string) $trigger->attr('data-confirm-token'));
    }
}
