<?php

declare(strict_types=1);

namespace App\Tests\Functional\OneC;

use App\Company\Entity\User;
use App\OneC\Application\CreateConnectionAction;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CabinetTest extends WebTestCaseBase
{
    public function testListAndCardShowOrganizationsAndPartsReceived(): void
    {
        $client = self::createClient();
        [, $token, $id] = $this->owner($client);
        $baseId = $this->handshake($client, $token, 200);
        $packageId = Uuid::uuid7()->toString();
        $this->package($client, $token, $baseId, $packageId, 1);

        $client->request('GET', '/onec/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-onec-connections]', 'База');

        $client->request('GET', '/onec/connections/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-onec-organizations]', 'ООО Ромашка');
        self::assertSelectorTextContains('[data-onec-package="'.$packageId.'"]', 'частей 1 из 2');
        self::assertSelectorTextContains('[data-onec-package="'.$packageId.'"]', 'Документ не найден');

        $this->package($client, $token, $baseId, $packageId, 2);
        $client->request('GET', '/onec/connections/'.$id);
        self::assertSelectorTextContains('[data-onec-package="'.$packageId.'"]', 'частей 2 из 2');
    }

    public function testRawPartIsDownloadableByOwnerAndAdminOnly(): void
    {
        $client = self::createClient();
        [, $token] = $this->owner($client);
        $baseId = $this->handshake($client, $token, 200);
        $body = $this->package($client, $token, $baseId, Uuid::uuid7()->toString(), 1);
        $partId = $this->em()->getConnection()->fetchOne('SELECT id FROM onec_package WHERE sha256 = ?', [hash('sha256', $body)]);

        $client->request('GET', '/onec/packages/'.$partId.'/raw');
        self::assertResponseIsSuccessful();
        self::assertSame($body, $client->getInternalResponse()->getContent());
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));

        $this->owner($client);
        $client->request('GET', '/onec/packages/'.$partId.'/raw');
        self::assertResponseStatusCodeSame(404);

        $userAdminId = Uuid::uuid7()->toString();
        $userAdmin = UserBuilder::aUser()->withId($userAdminId)->withEmail($userAdminId.'@example.test')->withRoles(['ROLE_ADMIN'])->build();
        $this->em()->persist($userAdmin);
        $this->em()->flush();
        $client->loginUser($userAdmin);
        $client->request('GET', '/onec/packages/'.$partId.'/raw');
        self::assertResponseStatusCodeSame(403);

        $adminId = Uuid::uuid7()->toString();
        $admin = UserBuilder::aUser()->withId($adminId)->withEmail($adminId.'@example.test')->withRoles(['ROLE_ADMIN'])->build();
        $this->em()->persist($admin);
        $this->em()->flush();
        $client->loginUser($admin, 'admin');
        $client->request('GET', '/admin/onec/packages/'.$partId.'/raw');
        self::assertResponseIsSuccessful();
        self::assertSame($body, $client->getInternalResponse()->getContent());
    }

    public function testReissueInvalidatesOldToken(): void
    {
        $client = self::createClient();
        [, $old, $id] = $this->owner($client);
        $baseId = $this->handshake($client, $old, 200);

        $client->request('POST', '/onec/connections/'.$id.'/reissue', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/onec/connections/'.$id.'/reissue', ['_token' => $this->csrfToken($client, 'onec_reissue_'.$id)]);
        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        $new = $client->getCrawler()->filter('[data-onec-token]')->text();
        self::assertNotSame($old, $new);

        $this->handshake($client, $old, 401, $baseId);
        $this->package($client, $old, $baseId, Uuid::uuid7()->toString(), 1, 401);
        $this->handshake($client, $new, 200, $baseId);
        self::assertSame(1, $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE entity_id = ? AND action = 'UPDATE'", [$id]));
    }

    public function testDisableRevokesTokenAndReissueEnablesAgain(): void
    {
        $client = self::createClient();
        [, $token, $id] = $this->owner($client);
        $baseId = $this->handshake($client, $token, 200);

        $client->request('POST', '/onec/connections/'.$id.'/disable', ['_token' => $this->csrfToken($client, 'onec_disable_'.$id)]);
        self::assertResponseRedirects('/onec/connections/'.$id);
        $db = $this->em()->getConnection();
        self::assertSame('disabled', $db->fetchOne('SELECT status FROM onec_connection WHERE id = ?', [$id]));
        $this->handshake($client, $token, 401, $baseId);
        $this->package($client, $token, $baseId, Uuid::uuid7()->toString(), 1, 401);

        $client->request('POST', '/onec/connections/'.$id.'/reissue', ['_token' => $this->csrfToken($client, 'onec_reissue_'.$id)]);
        self::assertResponseIsSuccessful();
        self::assertSame('pending', $db->fetchOne('SELECT status FROM onec_connection WHERE id = ?', [$id]));
        $new = $client->getCrawler()->filter('[data-onec-token]')->text();
        $this->package($client, $new, $baseId, Uuid::uuid7()->toString(), 1, 409);
        $this->handshake($client, $new, 200, $baseId);
        $this->package($client, $new, $baseId, Uuid::uuid7()->toString(), 1);
    }

    public function testForeignConnectionIsNotFound(): void
    {
        $client = self::createClient();
        [, , $id] = $this->owner($client);
        $this->owner($client);
        $client->request('GET', '/onec/connections/'.$id);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/onec/connections/'.$id.'/disable', ['_token' => $this->csrfToken($client, 'onec_disable_'.$id)]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/onec/connections/'.$id.'/reissue', ['_token' => $this->csrfToken($client, 'onec_reissue_'.$id)]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('pending', $this->em()->getConnection()->fetchOne('SELECT status FROM onec_connection WHERE id = ?', [$id]));
    }

    /** @return array{User, string, string} */
    private function owner(KernelBrowser $client): array
    {
        $ownerId = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withId($ownerId)->withEmail($ownerId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $id = Uuid::uuid7()->toString();
        $token = static::getContainer()->get(CreateConnectionAction::class)($id, (string) $company->getId(), $ownerId, 'База');

        return [$owner, $token, $id];
    }

    private function handshake(KernelBrowser $client, string $token, int $status, ?string $baseId = null): string
    {
        $baseId ??= Uuid::uuid4()->toString();
        $client->jsonRequest('POST', '/api/onec/v1/handshake', [
            'base_id' => $baseId, 'config_name' => 'БухгалтерияПредприятия', 'config_version' => '3.0.1.1',
            'platform_version' => '8.3', 'processor_version' => '1.0',
            'organizations' => [['guid' => Uuid::uuid4()->toString(), 'name' => 'ООО Ромашка', 'inn' => '7701234567', 'kpp' => null]],
        ], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame($status);

        return $baseId;
    }

    private function package(KernelBrowser $client, string $token, string $baseId, string $packageId, int $part, int $status = 200): string
    {
        $body = json_encode([
            'package_id' => $packageId, 'base_id' => $baseId, 'kind' => 'invoices', 'processor_version' => '1.0',
            'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'include_open_outside_period' => false, 'snapshot_at' => '2026-11-20T09:00:00+03:00',
            'part' => $part, 'parts_total' => 2, 'warnings' => ['Документ не найден'], 'documents' => [],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $client->request('POST', '/api/onec/v1/packages', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], content: $body);
        self::assertResponseStatusCodeSame($status);

        return $body;
    }
}
