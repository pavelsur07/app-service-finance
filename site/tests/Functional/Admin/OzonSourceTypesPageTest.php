<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class OzonSourceTypesPageTest extends WebTestCaseBase
{
    private const URL = '/admin/marketplace/ozon-source-types';

    public function testAdminConfirmsPendingCodeWithDraftPrefilled(): void
    {
        $client = static::createClient();
        $admin = UserBuilder::aUser()->withIndex(1)->withEmail('admin+moz@example.test')->withRoles(['ROLE_ADMIN'])->build();
        $this->em()->persist($admin);
        $this->em()->flush();
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement(
            "INSERT INTO moz_source_type (source_type, name, kind, status, ai_draft, first_seen_company_id, first_seen_date, first_seen_at)
             VALUES ('ozon:service:12', 'Кросс-докинг', NULL, 'pending', :draft, :company, '2026-11-10', now())",
            ['draft' => json_encode(['status' => 'needs_review', 'kind' => 'logistics_to', 'new_kind' => null, 'title' => 'Кросс-докинг Ozon', 'reason' => 'логистика до склада', 'confidence' => 0.7], \JSON_THROW_ON_ERROR), 'company' => Uuid::uuid7()->toString()],
        );

        $client->loginUser($admin, 'admin');
        $crawler = $client->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-source-type="ozon:service:12"]');
        self::assertStringContainsString('логистика до склада', $row->text());
        self::assertSame('logistics_to', $row->filter('select[name=kind] option[selected]')->attr('value'));

        $client->submit($row->filter('form')->form());
        self::assertResponseRedirects(self::URL);
        self::assertSame(['logistics_to', 'Кросс-докинг Ozon'], array_values((array) $db->fetchAssociative("SELECT kind, name FROM moz_source_type WHERE source_type = 'ozon:service:12'")));

        $client->followRedirect();
        self::assertStringContainsString('Все коды решены', (string) $client->getResponse()->getContent());
    }

    public function testNonAdminIsForbidden(): void
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->withIndex(2)->withEmail('user+moz@example.test')->build();
        $this->em()->persist($user);
        $this->em()->flush();

        $client->loginUser($user, 'admin');
        $client->request('GET', self::URL);
        self::assertResponseStatusCodeSame(403);
    }
}
