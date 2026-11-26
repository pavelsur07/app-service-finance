<?php

declare(strict_types=1);

namespace App\Tests\Functional\OneC;

use App\OneC\Application\CreateConnectionAction;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PackagesTest extends WebTestCaseBase
{
    private const URL = '/api/onec/v1/packages';

    public function testPartIsStoredOnceAndRepeatReturnsSameAnswer(): void
    {
        [$client, $token, $baseId] = $this->connected();
        $body = $this->body($baseId);

        $first = $this->send($client, $token, $body);
        self::assertResponseIsSuccessful();
        $package = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['accepted' => true, 'package_id' => $package['package_id'], 'part' => 1, 'status' => 'stored', 'duplicate' => false], $first);

        $db = $this->em()->getConnection();
        $row = $db->fetchAssociative('SELECT storage_path, sha256, byte_size, document_count, parts_total, warnings FROM onec_package WHERE package_id = ?', [$package['package_id']]);
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $body), $row['sha256']);
        self::assertSame(\strlen($body), $row['byte_size']);
        self::assertSame(1, $row['document_count']);
        self::assertSame(2, $row['parts_total']);
        self::assertSame(['Документ не найден'], json_decode($row['warnings'], true));
        self::assertStringStartsWith('onec/', $row['storage_path']);
        $storage = static::getContainer()->get(ObjectStorageInterface::class);
        self::assertSame($body, gzdecode($storage->read($row['storage_path'])));
        self::assertNotNull($db->fetchOne('SELECT last_success_at FROM onec_connection WHERE base_id = ?', [$baseId]));

        $repeat = $this->send($client, $token, $body);
        self::assertResponseIsSuccessful();
        self::assertSame(array_replace($first, ['duplicate' => true]), $repeat);
        self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM onec_package WHERE package_id = ?', [$package['package_id']]));

        $changed = $this->body($baseId, ['package_id' => $package['package_id'], 'comment' => 'другое тело']);
        $this->send($client, $token, $changed);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('package_conflict', $this->error($client));
        self::assertSame($body, gzdecode($storage->read($row['storage_path'])));
        self::assertFalse($storage->exists(\dirname($row['storage_path']).'/1-'.hash('sha256', $changed).'.json.gz'));

        $second = $this->body($baseId, ['package_id' => $package['package_id'], 'part' => 2]);
        $this->send($client, $token, $second);
        self::assertResponseIsSuccessful();
        $mismatch = $this->body($baseId, ['package_id' => $package['package_id'], 'part' => 3, 'parts_total' => 3]);
        $this->send($client, $token, $mismatch);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('package_conflict', $this->error($client));
    }

    public function testEnvelopeIsValidated(): void
    {
        [$client, $token, $baseId] = $this->connected();
        foreach ([
            ['kind' => 'bank_statements'],
            ['package_id' => 'not-a-uuid'],
            ['part' => 3],
            ['part' => 0],
            ['period_from' => '2026-12-01'],
            ['period_to' => '2026-02-30'],
            ['documents' => null],
            ['documents' => array_fill(0, 501, [])],
            ['include_open_outside_period' => 'yes'],
        ] as $override) {
            $this->send($client, $token, $this->body($baseId, $override));
            self::assertResponseStatusCodeSame(422, json_encode($override, \JSON_THROW_ON_ERROR));
            self::assertSame('invalid_package', $this->error($client));
        }
        $client->request('POST', self::URL, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], content: '{');
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', self::URL, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], content: str_repeat(' ', 10 * 1024 * 1024 + 1));
        self::assertResponseStatusCodeSame(413);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM onec_package p JOIN onec_connection c ON c.id = p.connection_id WHERE c.base_id = ?', [$baseId]));
    }

    public function testTokenBaseAndSupportAreChecked(): void
    {
        [$client, $token, $baseId] = $this->connected();
        $this->send($client, str_repeat('0', 64), $this->body($baseId));
        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_token', $this->error($client));
        $client->request('POST', self::URL, content: $this->body($baseId));
        self::assertResponseStatusCodeSame(401);

        $this->send($client, $token, $this->body(Uuid::uuid4()->toString()));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('base_conflict', $this->error($client));

        [, $fresh] = $this->connection($client);
        $this->send($client, $fresh, $this->body($baseId));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('base_conflict', $this->error($client));

        $client->jsonRequest('POST', '/api/onec/v1/handshake', $this->handshake($baseId, '2.0.66.1'), ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        self::assertSame('unsupported', json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['support']['level']);
        $this->send($client, $token, $this->body($baseId));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unsupported', $this->error($client));
    }

    public function testOwnersAreIsolated(): void
    {
        [$client, $token, $baseId] = $this->connected();
        [, $foreignToken, $foreignBase] = $this->connected($client, $baseId);
        self::assertSame($baseId, $foreignBase);
        $body = $this->body($baseId);
        $this->send($client, $token, $body);
        self::assertResponseIsSuccessful();
        $this->send($client, $foreignToken, $body);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->decode($client)['duplicate']);
        $packageId = json_decode($body, true, flags: \JSON_THROW_ON_ERROR)['package_id'];
        self::assertSame(2, $this->em()->getConnection()->fetchOne('SELECT COUNT(DISTINCT connection_id) FROM onec_package WHERE package_id = ?', [$packageId]));
    }

    public function testRateLimit(): void
    {
        [$client, $token, $baseId] = $this->connected();
        $id = $this->em()->getConnection()->fetchOne('SELECT id FROM onec_connection WHERE token_hash = ?', [hash('sha256', $token)]);
        $limiter = static::getContainer()->get('limiter.onec_packages')->create($id);
        $limiter->reset();
        $limiter->consume(120);
        $this->send($client, $token, $this->body($baseId));
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Retry-After', '60');
    }

    /** @return array{KernelBrowser, string, string} */
    private function connected(?KernelBrowser $client = null, ?string $baseId = null): array
    {
        [$client, $token] = $this->connection($client);
        $baseId ??= Uuid::uuid4()->toString();
        $client->jsonRequest('POST', '/api/onec/v1/handshake', $this->handshake($baseId), ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        return [$client, $token, $baseId];
    }

    /** @return array{KernelBrowser, string} */
    private function connection(?KernelBrowser $client = null): array
    {
        $client ??= self::createClient();
        $ownerId = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withId($ownerId)->withEmail($ownerId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();
        $token = static::getContainer()->get(CreateConnectionAction::class)(Uuid::uuid7()->toString(), (string) $company->getId(), $ownerId, 'База');

        return [$client, $token];
    }

    /** @return array<string, mixed> */
    private function handshake(string $baseId, string $configVersion = '3.0.1.1'): array
    {
        return [
            'base_id' => $baseId,
            'config_name' => 'БухгалтерияПредприятия',
            'config_version' => $configVersion,
            'platform_version' => '8.3',
            'processor_version' => '1.0',
            'organizations' => [],
        ];
    }

    /** @param array<string, mixed> $override */
    private function body(string $baseId, array $override = []): string
    {
        return json_encode(array_replace([
            'package_id' => Uuid::uuid7()->toString(),
            'base_id' => $baseId,
            'kind' => 'invoices',
            'processor_version' => '1.0',
            'period_from' => '2026-11-01',
            'period_to' => '2026-11-30',
            'include_open_outside_period' => true,
            'part' => 1,
            'parts_total' => 2,
            'warnings' => ['Документ не найден'],
            'documents' => [['guid' => Uuid::uuid4()->toString(), 'type' => 'customer_invoice', 'amount' => '100.00']],
            'unknown_field' => 'игнорируется',
        ], $override), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function send(KernelBrowser $client, string $token, string $body): array
    {
        $client->request('POST', self::URL, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'], content: $body);

        return $this->decode($client);
    }

    /** @return array<string, mixed> */
    private function decode(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function error(KernelBrowser $client): string
    {
        return $this->decode($client)['error'];
    }
}
