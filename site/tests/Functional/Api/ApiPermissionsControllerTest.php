<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Domain\ApiKeySecret;
use App\Api\Domain\ApiScopeCatalog;
use App\Api\Entity\ApiKey;
use App\Api\Repository\ApiKeyRepository;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class ApiPermissionsControllerTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private Company $company;
    private ApiKey $key;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $clock = new MockClock('2026-09-13 12:00:00 UTC');
        static::getContainer()->set(ClockInterface::class, $clock);
        $owner = UserBuilder::aUser()->withIndex(9870)->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(9870)->withOwner($owner)->build();
        $secret = ApiKeySecret::generate();
        $this->key = new ApiKey((string) $this->company->getId(), 'Permissions integration', $secret['publicIdentifier'], ApiKeySecret::hash($secret['secret']), (string) $owner->getId(), $clock->now());
        $this->token = ApiKeySecret::format($secret['publicIdentifier'], $secret['secret']);
        foreach ([$owner, $this->company, $this->key] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->client->loginUser($owner);
        $this->setClientSessionValue($this->client, 'active_company_id', $this->company->getId());
    }

    public function testMatrixShowsOnlyExplicitScopesAndDisconnectedControls(): void
    {
        $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(16, 'input[data-api-scope]');
        self::assertSelectorCount(0, 'input[data-api-scope]:checked');
        self::assertSelectorCount(10, 'input[data-api-resource-enable][disabled]');
        self::assertSelectorTextContains('.alert--warning', 'Права подготовлены. Доступ появится после подключения раздела и вашего включения');
        self::assertSelectorNotExists('input[value="accounts.create"]');
        $presets = $this->client->getCrawler()->filter('[data-api-preset]')->each(static fn ($node): array => json_decode((string) $node->attr('data-api-preset'), true, flags: \JSON_THROW_ON_ERROR));
        self::assertSame(array_values(ApiScopeCatalog::presets()), $presets);
        self::assertStringNotContainsString($this->token, (string) $this->client->getResponse()->getContent());
    }

    public function testPreparedRightsPersistWithoutEnablingAccessOrChangingExpiry(): void
    {
        $data = $this->formData();
        $data['api_key_permissions']['selectedScopes'] = ['accounts.read', 'cash_transactions.create'];
        $this->client->request('POST', $this->url(), $data);
        self::assertResponseRedirects($this->url(), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Права сохранены.');
        self::assertSelectorCount(2, 'input[data-api-scope]:checked');
        $key = $this->reloadKey();
        self::assertSame(['accounts.read', 'cash_transactions.create'], $key->getSelectedScopes());
        self::assertSame([], $key->getEnabledResources());
        self::assertSame('2026-12-12T12:00:00+00:00', $key->getExpiresAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        $this->client->request('GET', '/api/external/v1/auth/check', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'HTTP_X_COMPANY_ID' => (string) $this->company->getPublicId(), 'REMOTE_ADDR' => '192.0.2.97']);
        self::assertResponseIsSuccessful();
        $details = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['accounts.read', 'cash_transactions.create'], $details['prepared_scopes']);
        self::assertSame([], $details['effective_scopes']);
    }

    public function testSaveRequiresCsrfAndValidVersion(): void
    {
        $this->client->request('POST', $this->url());
        self::assertResponseStatusCodeSame(422);
        $data = $this->formData();
        unset($data['api_key_permissions']['_token']);
        $data['api_key_permissions']['selectedScopes'] = ['accounts.read'];
        $this->client->request('POST', $this->url(), $data);
        self::assertResponseStatusCodeSame(422);
        foreach (['0', '-1', '1.5', 'abc', '999999999999999999999'] as $version) {
            $data = $this->formData();
            $data['api_key_permissions']['version'] = $version;
            $this->client->request('POST', $this->url(), $data);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame([], $this->reloadKey()->getSelectedScopes());
    }

    public function testUnknownScopesAndDisconnectedEnableAreRejected(): void
    {
        foreach ([['selectedScopes' => ['accounts.create']], ['selectedScopes' => ['*']], ['enabledResources' => ['accounts']]] as $invalid) {
            $data = $this->formData();
            $data['api_key_permissions'] = array_replace($data['api_key_permissions'], $invalid);
            $this->client->request('POST', $this->url(), $data);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorExists('.field-helper.error');
        }
        self::assertSame([], $this->reloadKey()->getSelectedScopes());
        self::assertSame([], $this->reloadKey()->getEnabledResources());
    }

    public function testConflictOffersReloadWithoutOverwritingNewerRights(): void
    {
        $data = $this->formData();
        $data['api_key_permissions']['selectedScopes'] = ['accounts.read'];
        $this->em()->getConnection()->executeStatement('UPDATE api_keys SET selected_scopes = ?, version = version + 1 WHERE id = ?', ['["projects.read"]', $this->key->getId()]);
        $this->client->request('POST', $this->url(), $data);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorExists('a[href="'.$this->url().'"]');
        self::assertSelectorTextContains('.alert--danger', 'Настройки ключа изменились');
        self::assertSame('["projects.read"]', $this->em()->getConnection()->fetchOne('SELECT selected_scopes FROM api_keys WHERE id = ?', [$this->key->getId()]));
        $this->client->clickLink('Перечитать права');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[value="projects.read"]:checked');
        self::assertSelectorNotExists('input[value="accounts.read"]:checked');
    }

    public function testActualOwnerRequiredDespiteAdminWriteMembership(): void
    {
        $otherOwner = UserBuilder::aUser()->withIndex(9871)->build();
        $other = CompanyBuilder::aCompany()->withIndex(9871)->withOwner($otherOwner)->build();
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Permissions admin', ['admin' => 'write'], $this->company);
        $member = CompanyMemberBuilder::aMember()->withCompany($this->company)->withUser($otherOwner)->withAccessRole($role)->build();
        foreach ([$otherOwner, $other, $role, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->client->loginUser($otherOwner);
        $this->setClientSessionValue($this->client, 'active_company_id', $this->company->getId());
        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, $this->url());
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame([], $this->reloadKey()->getSelectedScopes());
    }

    public function testOtherCompanyKeyCannotBeReadOrChanged(): void
    {
        $owner = $this->company->getUser();
        self::assertNotNull($owner);
        $other = CompanyBuilder::aCompany()->withIndex(9872)->withOwner($owner)->build();
        $this->em()->persist($other);
        $this->em()->flush();
        $this->setClientSessionValue($this->client, 'active_company_id', $other->getId());
        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, $this->url());
            self::assertResponseStatusCodeSame(404);
        }
        self::assertSame([], $this->reloadKey()->getSelectedScopes());
    }

    /** @return array<string, array<string, mixed>> */
    private function formData(): array
    {
        $crawler = $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();

        return $crawler->selectButton('Сохранить права')->form()->getPhpValues();
    }

    private function reloadKey(): ApiKey
    {
        $key = static::getContainer()->get(ApiKeyRepository::class)->findOneByIdAndCompany($this->key->getId(), (string) $this->company->getId());
        self::assertNotNull($key);
        $this->em()->refresh($key);

        return $key;
    }

    private function url(): string
    {
        return '/settings/api/'.$this->key->getId().'/permissions';
    }
}
