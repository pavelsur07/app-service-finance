<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class WbSourceTypesPageTest extends WebTestCaseBase
{
    private const URL = '/admin/marketplace/wb-source-types';

    public function testAdminConfirmsPendingCode(): void
    {
        $client = static::createClient();
        $admin = UserBuilder::aUser()->withIndex(1)->withEmail('admin+mwb@example.test')->withRoles(['ROLE_ADMIN'])->build();
        $this->em()->persist($admin);
        $this->em()->flush();
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement(
            "INSERT INTO mwb_source_type (source_type, name, kind, status, first_seen_company_id, first_seen_date, first_seen_at)
             VALUES ('wb:field:paidAcceptance', 'Платная приёмка', NULL, 'pending', :company, '2026-11-10', now())",
            ['company' => Uuid::uuid7()->toString()],
        );

        $client->loginUser($admin, 'admin');
        $crawler = $client->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-source-type="wb:field:paidAcceptance"]');
        self::assertSame('storage', $row->filter('select[name="kind"] option[selected]')->attr('value'), 'предложенный вид выбран заранее');
        self::assertStringContainsString('Предложено: Хранение', $row->text());
        $form = $crawler->filter('[data-source-type="wb:field:paidAcceptance"] form')->form(['kind' => 'storage']);

        $client->submit($form);
        self::assertResponseRedirects(self::URL);
        self::assertSame(['storage', 'confirmed'], array_values((array) $db->fetchAssociative("SELECT kind, status FROM mwb_source_type WHERE source_type = 'wb:field:paidAcceptance'")));

        $client->followRedirect();
        self::assertStringContainsString('Все коды решены', (string) $client->getResponse()->getContent());
    }

    public function testAiDraftIsShownAndPreselected(): void
    {
        $client = static::createClient();
        $admin = UserBuilder::aUser()->withIndex(3)->withEmail('admin2+mwb@example.test')->withRoles(['ROLE_ADMIN'])->build();
        $this->em()->persist($admin);
        $this->em()->flush();
        static::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO mwb_source_type (source_type, name, kind, status, ai_draft, first_seen_company_id, first_seen_date, first_seen_at)
             VALUES ('wb:payout:компенсация', 'компенсация', NULL, 'pending', :draft, :company, '2026-11-10', now())",
            ['company' => Uuid::uuid7()->toString(), 'draft' => json_encode(['task_id' => 't1', 'status' => 'needs_review', 'kind' => 'compensation', 'new_kind' => null, 'title' => 'Компенсация при возврате', 'reason' => 'выплата WB продавцу', 'confidence' => 0.8], \JSON_UNESCAPED_UNICODE)],
        );

        $client->loginUser($admin, 'admin');
        $crawler = $client->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-source-type="wb:payout:компенсация"]');
        self::assertStringContainsString('выплата WB продавцу', $row->text());
        self::assertSame('compensation', $row->filter('select[name="kind"] option[selected]')->attr('value'));
        self::assertSame('Компенсация при возврате', $row->filter('input[name="name"]')->attr('value'));
    }

    public function testNonAdminIsForbidden(): void
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->withIndex(2)->withEmail('user+mwb@example.test')->build();
        $this->em()->persist($user);
        $this->em()->flush();

        $client->loginUser($user, 'admin');
        $client->request('GET', self::URL);
        self::assertResponseStatusCodeSame(403);
    }
}
