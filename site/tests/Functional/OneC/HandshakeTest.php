<?php

declare(strict_types=1);

namespace App\Tests\Functional\OneC;

use App\Company\Entity\CompanyRole;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class HandshakeTest extends WebTestCaseBase
{
    public function testHandshakeBindsBaseAndPreservesMapping(): void
    {
        [$client, $token, $companyId] = $this->createConnection();
        $body = $this->payload();
        $client->jsonRequest('POST', '/api/onec/v1/handshake', $body, ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $result = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('connected', $result['status']);
        self::assertSame('supported', $result['support']['level']);
        self::assertFalse($result['organizations'][0]['mapped']);
        $db = $this->em()->getConnection();
        self::assertSame(hash('sha256', $token), $db->fetchOne('SELECT token_hash FROM onec_connection WHERE id = ?', [$result['connection_id']]));
        $db->update('onec_organization', ['company_id' => $companyId, 'enabled' => true], ['connection_id' => $result['connection_id']]);
        $client->jsonRequest('POST', '/api/onec/v1/handshake', array_replace($body, ['organizations' => []]), ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        self::assertFalse($db->fetchOne('SELECT present FROM onec_organization WHERE connection_id = ?', [$result['connection_id']]));
        $client->jsonRequest('POST', '/api/onec/v1/handshake', $body, ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $repeat = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue($repeat['organizations'][0]['mapped']);
        self::assertTrue($db->fetchOne('SELECT enabled FROM onec_organization WHERE connection_id = ?', [$result['connection_id']]));
        self::assertNotEmpty($repeat['organizations'][0]['company_name']);
        $body['base_id'] = Uuid::uuid4()->toString();
        $client->jsonRequest('POST', '/api/onec/v1/handshake', $body, ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testSessionDoesNotAuthorizeHandshake(): void
    {
        [$client] = $this->createConnection();
        $client->jsonRequest('POST', '/api/onec/v1/handshake', $this->payload(), ['HTTP_AUTHORIZATION' => 'Bearer invalid']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidSnapshotAndUnsupportedEdition(): void
    {
        [$client, $token] = $this->createConnection();
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $body = $this->payload();
        $client->jsonRequest('POST', '/api/onec/v1/handshake', array_replace($body, ['organizations' => null]), $headers);
        self::assertResponseStatusCodeSame(422);
        $body['config_version'] = '2.0.66.1';
        $client->jsonRequest('POST', '/api/onec/v1/handshake', $body, $headers);
        self::assertResponseIsSuccessful();
        $result = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('unsupported', $result['support']['level']);
    }

    public function testSameBaseIsUniquePerOwnerAndForeignMappingIsHidden(): void
    {
        [$client, $first] = $this->createConnection();
        $client->request('GET', '/onec/connections/new');
        $second = $client->submitForm('Создать подключение', ['name' => 'Копия'])->filter('[data-onec-token]')->text();
        [, $foreign, $foreignCompanyId] = $this->createConnection($client);
        $body = $this->payload();
        $url = '/api/onec/v1/handshake';
        $client->jsonRequest('POST', $url, $body, ['HTTP_AUTHORIZATION' => 'Bearer '.$first]);
        self::assertResponseIsSuccessful();
        $id = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['connection_id'];
        $client->jsonRequest('POST', $url, $body, ['HTTP_AUTHORIZATION' => 'Bearer '.$second]);
        self::assertResponseStatusCodeSame(409);
        $client->jsonRequest('POST', $url, $body, ['HTTP_AUTHORIZATION' => 'Bearer '.$foreign]);
        self::assertResponseIsSuccessful();
        $this->em()->getConnection()->update('onec_organization', ['company_id' => $foreignCompanyId], ['connection_id' => $id]);
        $client->jsonRequest('POST', $url, $body, ['HTTP_AUTHORIZATION' => 'Bearer '.$first]);
        self::assertResponseIsSuccessful();
        $result = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertNull($result['organizations'][0]['company_name']);
        self::assertFalse($result['organizations'][0]['mapped']);
    }

    public function testTokenCannotBeReadAgainAndCreatingRequiresOwnerAndCsrf(): void
    {
        [$client, $token, $companyId] = $this->createConnection();
        $client->request('GET', '/onec/connections/new');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($token, (string) $client->getResponse()->getContent());
        $client->request('POST', '/onec/connections/new', ['_token' => 'invalid', 'name' => 'Ещё одна база']);
        self::assertResponseStatusCodeSame(403);
        $company = $this->em()->find(\App\Company\Entity\Company::class, $companyId);
        self::assertNotNull($company);
        $memberId = Uuid::uuid7()->toString();
        $user = UserBuilder::aUser()->withId($memberId)->withEmail($memberId.'@example.test')->build();
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Администратор', ['admin' => 'write'], $company);
        $member = CompanyMemberBuilder::aMember()->withUser($user)->withCompany($company)->withAccessRole($role)->build();
        foreach ([$user, $role, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
        $client->request('GET', '/onec/connections/new');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM onec_connection WHERE owner_id = ?', [$company->getUser()?->getId()]));
    }

    public function testDuplicateFormIdCannotIssueASecondTokenEvenWithValidCsrf(): void
    {
        [$client, $original, $companyId] = $this->createConnection();
        $db = $this->em()->getConnection();
        $id = $db->fetchOne('SELECT id FROM onec_connection WHERE token_hash = ?', [hash('sha256', $original)]);
        $csrf = $this->csrfToken($client, 'onec_create_'.$companyId.'_'.$id);
        $client->request('POST', '/onec/connections/new', ['name' => 'Повтор', 'request_id' => $id, '_token' => $csrf]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM audit_log WHERE entity_id = ?', [$id]));
        self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM onec_connection WHERE id = ? AND token_hash = ?', [$id, hash('sha256', $original)]));
    }

    public function testLimitsAndInvalidSnapshotDoNotChangeConnection(): void
    {
        [$client, $token] = $this->createConnection();
        $body = $this->payload();
        $url = '/api/onec/v1/handshake';
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $client->request('POST', $url, server: $headers, content: str_repeat(' ', 10 * 1024 * 1024 + 1));
        self::assertResponseStatusCodeSame(413);
        $client->jsonRequest('POST', $url, $body, ['HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('0', 64)]);
        self::assertResponseStatusCodeSame(401);
        $body['organizations'][0]['inn'] = '123456789012';
        $client->jsonRequest('POST', $url, $body, $headers);
        self::assertResponseIsSuccessful();
        $id = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['connection_id'];
        $db = $this->em()->getConnection();
        self::assertNull($db->fetchOne('SELECT inn FROM onec_organization WHERE connection_id = ?', [$id]));
        $body['organizations'][] = $body['organizations'][0];
        $client->jsonRequest('POST', $url, $body, $headers);
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($db->fetchOne('SELECT present FROM onec_organization WHERE connection_id = ?', [$id]));
        $limiter = static::getContainer()->get('limiter.onec_handshake')->create($id);
        $limiter->reset();
        $limiter->consume(60);
        $client->jsonRequest('POST', $url, $body, $headers);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Retry-After', '60');
        $db->update('onec_connection', ['status' => 'disabled'], ['id' => $id]);
        $client->jsonRequest('POST', $url, $body, $headers);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array{KernelBrowser, string, string} */
    private function createConnection(?KernelBrowser $client = null): array
    {
        $client ??= self::createClient();
        $ownerId = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withId($ownerId)->withEmail($ownerId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('GET', '/onec/connections/new');
        self::assertResponseIsSuccessful();
        $page = $client->submitForm('Создать подключение', ['name' => 'Основная база']);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $token = $page->filter('[data-onec-token]')->text();
        self::assertNotEmpty($token);

        return [$client, $token, (string) $company->getId()];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'base_id' => Uuid::uuid4()->toString(),
            'config_name' => 'БухгалтерияПредприятия',
            'config_version' => '3.0.1.1',
            'config_synonym' => 'Бухгалтерия предприятия',
            'processor_version' => '1.0',
            'platform_version' => '8.3',
            'organizations' => [['guid' => Uuid::uuid4()->toString(), 'name' => 'Организация', 'inn' => null, 'kpp' => null]],
        ];
    }
}
