<?php

declare(strict_types=1);

namespace App\Tests\Functional\OneC;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\OneC\Application\CreateConnectionAction;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Подключение 1С принадлежит компании, а не пользователю. */
final class CompanyScopeTest extends WebTestCaseBase
{
    public function testConnectionOfCompanyAIsInvisibleFromCompanyBOfTheSameOwner(): void
    {
        $client = self::createClient();
        $owner = $this->user();
        $a = $this->company($owner, 'Альфа');
        $b = $this->company($owner, 'Бета');
        [$id, $token] = $this->connection($a, $owner, 'База Альфы');
        $baseId = $this->handshake($client, $token);
        $partId = $this->part($client, $token, $baseId);

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $b->getId());
        $client->request('GET', '/onec/connections');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('База Альфы', (string) $client->getResponse()->getContent());
        $client->request('GET', '/onec/connections/'.$id);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/onec/connections/'.$id.'/reissue', ['_token' => $this->csrfToken($client, 'onec_reissue_'.$id)]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/onec/connections/'.$id.'/disable', ['_token' => $this->csrfToken($client, 'onec_disable_'.$id)]);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/onec/packages/'.$partId.'/raw');
        self::assertResponseStatusCodeSame(404);

        $db = $this->em()->getConnection();
        self::assertSame('connected', $db->fetchOne('SELECT status FROM onec_connection WHERE id = ?', [$id]));
        self::assertSame(hash('sha256', $token), $db->fetchOne('SELECT token_hash FROM onec_connection WHERE id = ?', [$id]));
        self::assertSame(0, $db->fetchOne("SELECT COUNT(*) FROM audit_log WHERE entity_id = ? AND action = 'UPDATE'", [$id]));
    }

    public function testMemberWithFinanceRoleManagesConnectionAndAuditGoesToItsCompany(): void
    {
        $client = self::createClient();
        $owner = $this->user();
        $a = $this->company($owner, 'Альфа');
        [$id, $token] = $this->connection($a, $owner, 'База Альфы');
        $partId = $this->part($client, $token, $this->handshake($client, $token));
        $member = $this->member($a, ['finance' => 'write']);

        $client->loginUser($member);
        $this->setClientSessionValue($client, 'active_company_id', $a->getId());
        $client->request('GET', '/onec/connections');
        self::assertSelectorTextContains('[data-onec-connections]', 'База Альфы');
        $client->request('GET', '/onec/connections/'.$id);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/onec/packages/'.$partId.'/raw');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/onec/connections/'.$id.'/reissue', ['_token' => $this->csrfToken($client, 'onec_reissue_'.$id)]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/onec/connections/'.$id.'/disable', ['_token' => $this->csrfToken($client, 'onec_disable_'.$id)]);
        self::assertResponseRedirects('/onec/connections/'.$id);
        $client->request('GET', '/onec/connections/new');
        self::assertResponseIsSuccessful();

        self::assertSame(
            [['company_id' => (string) $a->getId(), 'actor_user_id' => (string) $member->getId()], ['company_id' => (string) $a->getId(), 'actor_user_id' => (string) $member->getId()]],
            $this->em()->getConnection()->fetchAllAssociative("SELECT company_id, actor_user_id FROM audit_log WHERE entity_id = ? AND action = 'UPDATE'", [$id]),
        );
    }

    public function testMemberWithoutFinanceRoleIsDenied(): void
    {
        $client = self::createClient();
        $owner = $this->user();
        $a = $this->company($owner, 'Альфа');
        [$id, $token] = $this->connection($a, $owner, 'База Альфы');
        $partId = $this->part($client, $token, $this->handshake($client, $token));

        $client->loginUser($this->member($a, ['catalog' => 'read']));
        $this->setClientSessionValue($client, 'active_company_id', $a->getId());
        foreach (['/onec/connections', '/onec/connections/'.$id, '/onec/packages/'.$partId.'/raw', '/onec/connections/new'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $client->loginUser($this->member($a, ['finance' => 'read']));
        $this->setClientSessionValue($client, 'active_company_id', $a->getId());
        $client->request('GET', '/onec/connections/'.$id);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/onec/connections/'.$id.'/reissue', ['_token' => $this->csrfToken($client, 'onec_reissue_'.$id)]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/onec/connections/'.$id.'/disable', ['_token' => $this->csrfToken($client, 'onec_disable_'.$id)]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/onec/connections/new', ['name' => 'Чужая', 'request_id' => Uuid::uuid7()->toString(), '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM onec_connection WHERE company_id = ?', [$a->getId()]));
        self::assertSame(hash('sha256', $token), $this->em()->getConnection()->fetchOne('SELECT token_hash FROM onec_connection WHERE id = ?', [$id]));
    }

    public function testExportFlagIsSetByFinanceWriterOfTheConnectionCompanyOnly(): void
    {
        $client = self::createClient();
        $owner = $this->user();
        $a = $this->company($owner, 'Альфа');
        $b = $this->company($owner, 'Бета');
        [$id, $token] = $this->connection($a, $owner, 'База Альфы');
        $this->handshake($client, $token);
        $guid = '0b3c4d5e-6f70-4182-93a4-b5c6d7e8f901';
        $url = '/onec/connections/'.$id.'/organizations/'.$guid.'/export';
        $db = $this->em()->getConnection();

        $client->loginUser($this->member($a, ['finance' => 'read']));
        $this->setClientSessionValue($client, 'active_company_id', $a->getId());
        $client->request('GET', '/onec/connections/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-onec-export-form]');
        self::assertSelectorTextContains('[data-onec-export="'.$guid.'"]', 'нет');
        $client->request('POST', $url, ['allowed' => '1', '_token' => $this->csrfToken($client, 'onec_export_'.$id)]);
        self::assertResponseStatusCodeSame(403);

        $writer = $this->member($a, ['finance' => 'write']);
        $client->loginUser($writer);
        $this->setClientSessionValue($client, 'active_company_id', $a->getId());
        $client->request('GET', '/onec/connections/'.$id);
        self::assertSelectorExists('[data-onec-export-form] input[type=checkbox][name=allowed]:not([checked])');
        $client->request('POST', $url, ['allowed' => '1', '_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/onec/connections/'.$id.'/organizations/'.Uuid::uuid4()->toString().'/export', ['allowed' => '1', '_token' => $this->csrfToken($client, 'onec_export_'.$id)]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $url, ['allowed' => '1', '_token' => $this->csrfToken($client, 'onec_export_'.$id)]);
        self::assertResponseRedirects('/onec/connections/'.$id);
        self::assertTrue($db->fetchOne('SELECT export_allowed FROM onec_organization WHERE connection_id = ? AND guid = ?', [$id, $guid]));
        $client->request('POST', $url, ['allowed' => '1', '_token' => $this->csrfToken($client, 'onec_export_'.$id)]);
        self::assertResponseRedirects('/onec/connections/'.$id);
        $client->request('GET', '/onec/connections/'.$id);
        self::assertSelectorExists('[data-onec-export-form] input[name=allowed][checked]');
        self::assertSame(
            [['company_id' => (string) $a->getId(), 'actor_user_id' => (string) $writer->getId(), 'diff' => '{"organization": "'.$guid.'", "export_allowed": [false, true]}']],
            $db->fetchAllAssociative("SELECT company_id, actor_user_id, CAST(diff AS jsonb)::text AS diff FROM audit_log WHERE entity_id = ? AND action = 'UPDATE'", [$id]),
        );
        $this->handshake($client, $token, $db->fetchOne('SELECT base_id FROM onec_connection WHERE id = ?', [$id]));
        self::assertSame([true], array_column($this->decode($client)['organizations'], 'export_allowed'));

        // Галочка снимается: в форме нет allowed.
        $client->loginUser($writer);
        $this->setClientSessionValue($client, 'active_company_id', $a->getId());
        $client->request('POST', $url, ['_token' => $this->csrfToken($client, 'onec_export_'.$id)]);
        self::assertResponseRedirects('/onec/connections/'.$id);
        self::assertFalse($db->fetchOne('SELECT export_allowed FROM onec_organization WHERE connection_id = ? AND guid = ?', [$id, $guid]));

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $b->getId());
        $client->request('POST', $url, ['allowed' => '1', '_token' => $this->csrfToken($client, 'onec_export_'.$id)]);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($db->fetchOne('SELECT export_allowed FROM onec_organization WHERE connection_id = ? AND guid = ?', [$id, $guid]));
    }

    public function testHandshakeNamesOnlyTheConnectionCompany(): void
    {
        $client = self::createClient();
        $owner = $this->user();
        $a = $this->company($owner, 'Альфа');
        $this->company($owner, 'Бета');
        [$id, $token] = $this->connection($a, $owner, 'База Альфы');
        $baseId = $this->handshake($client, $token);
        self::assertSame([false], array_column($this->decode($client)['organizations'], 'mapped'));
        self::assertSame([null], array_column($this->decode($client)['organizations'], 'company_name'));

        $this->em()->getConnection()->update('onec_organization', ['enabled' => true], ['connection_id' => $id]);
        $this->handshake($client, $token, $baseId);
        self::assertSame([true], array_column($this->decode($client)['organizations'], 'mapped'));
        self::assertSame(['Альфа'], array_column($this->decode($client)['organizations'], 'company_name'));
        self::assertStringNotContainsString('Бета', (string) $client->getResponse()->getContent());
    }

    public function testSameBaseIsUniquePerCompanyNotPerOwner(): void
    {
        $client = self::createClient();
        $owner = $this->user();
        $a = $this->company($owner, 'Альфа');
        [, $first] = $this->connection($a, $owner, 'Первая');
        [, $second] = $this->connection($a, $owner, 'Вторая');
        [, $otherCompany] = $this->connection($this->company($owner, 'Бета'), $owner, 'База Беты');
        $baseId = $this->handshake($client, $first);
        $this->handshake($client, $second, $baseId, 409);
        $this->handshake($client, $otherCompany, $baseId);
    }

    private function user(): User
    {
        $id = Uuid::uuid7()->toString();
        $user = UserBuilder::aUser()->withId($id)->withEmail($id.'@example.test')->build();
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function company(User $owner, string $name): Company
    {
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->withName($name)->build();
        $this->em()->persist($company);
        $this->em()->flush();

        return $company;
    }

    /** @param array<string, string> $permissions */
    private function member(Company $company, array $permissions): User
    {
        $user = $this->user();
        // После запросов клиента EntityManager другой: компанию перечитать.
        $company = $this->em()->find(Company::class, $company->getId());
        self::assertNotNull($company);
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Роль '.implode(',', array_keys($permissions)).' '.implode(',', $permissions), $permissions, $company);
        $this->em()->persist($role);
        $this->em()->persist(CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withUser($user)->withCompany($company)->withAccessRole($role)->build());
        $this->em()->flush();

        return $user;
    }

    /** @return array{string, string} */
    private function connection(Company $company, User $creator, string $name): array
    {
        $id = Uuid::uuid7()->toString();

        return [$id, static::getContainer()->get(CreateConnectionAction::class)($id, (string) $company->getId(), (string) $creator->getId(), $name)];
    }

    private function handshake(KernelBrowser $client, string $token, ?string $baseId = null, int $status = 200): string
    {
        $baseId ??= Uuid::uuid4()->toString();
        $client->jsonRequest('POST', '/api/onec/v1/handshake', [
            'base_id' => $baseId, 'config_name' => 'БухгалтерияПредприятия', 'config_version' => '3.0.1.1',
            'platform_version' => '8.3', 'processor_version' => '1.0',
            'organizations' => [['guid' => '0b3c4d5e-6f70-4182-93a4-b5c6d7e8f901', 'name' => 'ООО Ромашка', 'inn' => null, 'kpp' => null]],
        ], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame($status);

        return $baseId;
    }

    private function part(KernelBrowser $client, string $token, string $baseId): string
    {
        $body = json_encode([
            'package_id' => Uuid::uuid7()->toString(), 'base_id' => $baseId, 'kind' => 'invoices', 'processor_version' => '1.0',
            'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'include_open_outside_period' => false, 'snapshot_at' => '2026-11-20T09:00:00+03:00',
            'part' => 1, 'parts_total' => 1, 'warnings' => [], 'documents' => [], 'organization_guids' => ['0b3c4d5e-6f70-4182-93a4-b5c6d7e8f901'],
        ], \JSON_THROW_ON_ERROR);
        $this->em()->getConnection()->executeStatement('UPDATE onec_organization SET export_allowed = TRUE WHERE connection_id = (SELECT id FROM onec_connection WHERE token_hash = ?)', [hash('sha256', $token)]);
        $client->request('POST', '/api/onec/v1/packages', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], content: $body);
        self::assertResponseIsSuccessful();

        return (string) $this->em()->getConnection()->fetchOne('SELECT id FROM onec_package WHERE sha256 = ?', [hash('sha256', $body)]);
    }

    /** @return array<string, mixed> */
    private function decode(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
