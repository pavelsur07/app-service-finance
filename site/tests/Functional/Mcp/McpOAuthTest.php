<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Billing\Entity\Tariff;
use App\Billing\Enum\TariffCode;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Mcp\Security\McpAccess;
use App\Tests\Builders\Billing\CompanySubscriptionBuilder;
use App\Tests\Builders\Billing\TariffBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Defuse\Crypto\Key;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[BackupGlobals(true)]
final class McpOAuthTest extends WebTestCaseBase
{
    private const ORIGIN = 'https://app.example.test';
    private const REDIRECT = 'http://127.0.0.1:8765/callback';
    private const VERIFIER = 'test-pkce-verifier-with-enough-unreserved-characters';
    private const SCOPES = 'accounts.read cash_drafts.create';

    private KernelBrowser $browser;
    private string $lastChallenge = '';

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $private));
        $public = openssl_pkey_get_details($key);
        self::assertNotFalse($public);
        $_ENV['MCP_OAUTH_ORIGIN'] = self::ORIGIN;
        $_ENV['MCP_OAUTH_PRIVATE_KEY'] = $private;
        $_ENV['MCP_OAUTH_PUBLIC_KEY'] = $public['key'];
        $_ENV['MCP_OAUTH_ENCRYPTION_KEY'] = Key::createNewRandomKey()->saveToAsciiSafeString();
        $_ENV['MCP_OAUTH_CLIENTS'] = json_encode(['codex-cli' => [self::REDIRECT]], \JSON_THROW_ON_ERROR);

        $this->browser = static::createClient(server: ['REMOTE_ADDR' => '192.0.2.'.random_int(1, 254)]);
        $this->browser->disableReboot();
    }

    public function testDiscoveryAndBearerChallengeUseTheCanonicalResource(): void
    {
        $this->browser->request('GET', self::ORIGIN.'/.well-known/oauth-protected-resource/mcp');
        self::assertResponseIsSuccessful();
        self::assertSame(self::ORIGIN.'/mcp', $this->json()['resource']);
        self::assertSame([self::ORIGIN], $this->json()['authorization_servers']);

        $this->browser->request('GET', self::ORIGIN.'/.well-known/oauth-authorization-server');
        self::assertResponseIsSuccessful();
        self::assertSame(self::ORIGIN.'/oauth/authorize', $this->json()['authorization_endpoint']);
        self::assertSame(['S256'], $this->json()['code_challenge_methods_supported']);
        self::assertArrayNotHasKey('registration_endpoint', $this->json());

        $this->assertDeniedAccess('vfd_foreign_api_token', 401);
        self::assertStringContainsString(self::ORIGIN.'/.well-known/oauth-protected-resource/mcp', $this->lastChallenge);
        $this->browser->request('GET', 'https://wrong.example.test/.well-known/oauth-protected-resource/mcp');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOauthEndpointsAreDisabledWithoutSigningConfiguration(): void
    {
        unset($_ENV['MCP_OAUTH_PRIVATE_KEY'], $_SERVER['MCP_OAUTH_PRIVATE_KEY']);
        $this->browser->request('GET', self::ORIGIN.'/.well-known/oauth-authorization-server');
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('POST', self::ORIGIN.'/mcp');
        self::assertResponseStatusCodeSame(404);
    }

    public function testBrowserSessionAndForeignOriginAreNotAcceptedAtTheResource(): void
    {
        [$owner] = $this->ownerAndCompany();
        $this->browser->loginUser($owner);
        $this->browser->request('POST', self::ORIGIN.'/mcp');
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('resource_metadata=', (string) $this->browser->getResponse()->headers->get('WWW-Authenticate'));
        self::assertNull($this->browser->getResponse()->headers->get('Location'));
        $this->browser->request('POST', self::ORIGIN.'/mcp', server: ['HTTP_ORIGIN' => 'https://foreign.example.test']);
        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, string|list<string>> $override */
    #[DataProvider('invalidAuthorizationRequests')]
    public function testBadAuthorizationRequestNeverRedirects(array $override): void
    {
        $this->start($override);
        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->browser->getResponse()->headers->get('Location'));
    }

    /** @return iterable<string, array{array<string, string|list<string>>}> */
    public static function invalidAuthorizationRequests(): iterable
    {
        yield 'plain PKCE' => [['code_challenge_method' => 'plain']];
        yield 'admin audience' => [['resource' => self::ORIGIN.'/mcp/admin']];
        yield 'external redirect' => [['redirect_uri' => 'https://unregistered.example.test/callback']];
        yield 'different loopback port' => [['redirect_uri' => 'http://127.0.0.1:8766/callback']];
        yield 'non-scalar redirect' => [['redirect_uri' => [self::REDIRECT]]];
    }

    public function testRepeatedOAuthParametersAreRejectedBeforeUse(): void
    {
        $this->start([], '&%63lient_id=codex-cli');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_request', $this->json()['error']);

        $this->browser->request(
            'POST', self::ORIGIN.'/oauth/revoke', ['client_id' => 'codex-cli', 'token' => 'sample'], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'client_id=codex-cli&token=first&token=sample',
        );
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_request', $this->json()['error']);
    }

    public function testOnlyTheActualOwnerWithBillingCanApprove(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $this->subscribe($company);
        $member = UserBuilder::aUser()->withIndex(32765)->build();
        $membership = CompanyMemberBuilder::aMember()->withCompany($company)->withUser($member)->withRole(CompanyMember::ROLE_OWNER)->build();
        $this->em()->persist($member);
        $this->em()->persist($membership);
        $this->em()->flush();

        $this->browser->loginUser($member);
        $consent = $this->consentUrl();
        $this->browser->request('GET', $consent);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('option[value="'.$company->getId().'"]');
        $this->approve($consent, $company);
        self::assertResponseStatusCodeSame(403);

        $this->em()->getConnection()->executeStatement('DELETE FROM billing_company_subscriptions WHERE company_id = ?', [$company->getId()]);
        $this->em()->clear();
        $this->browser->loginUser($owner);
        $consent = $this->consentUrl();
        $this->approve($consent, $company);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCodeRequiresVerifierAndConsentAndCannotBeExchangedTwice(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $this->subscribe($company);
        $this->browser->loginUser($owner);
        $consent = $this->consentUrl();
        $this->browser->request('POST', $consent, ['companyId' => $company->getId(), 'decision' => 'approve']);
        self::assertResponseStatusCodeSame(403);

        $code = $this->approve($consent, $company);
        $this->token(['code_verifier' => str_repeat('x', 43)] + $this->exchange($code));
        self::assertResponseStatusCodeSame(400);
        $this->token(['resource' => self::ORIGIN.'/mcp/admin'] + $this->exchange($code));
        self::assertResponseStatusCodeSame(400);
        $this->token($this->exchange($code));
        self::assertResponseIsSuccessful();
        self::assertTrue($this->browser->getResponse()->headers->hasCacheControlDirective('no-store'));
        $token = $this->json()['access_token'];
        $principal = static::getContainer()->get(McpAccess::class)->authenticate($this->bearer($token));
        self::assertSame($company->getId(), $principal->companyId);
        self::assertSame($owner->getId(), $principal->userId);
        self::assertSame(['accounts.read', 'cash_drafts.create'], $principal->scopes);

        $this->token($this->exchange($code));
        self::assertResponseStatusCodeSame(400);
    }

    public function testRefreshRotationAndReplayImmediatelyRevokeTheConnection(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $this->subscribe($company);
        $this->browser->loginUser($owner);
        $code = $this->approve($this->consentUrl(), $company);
        $this->token($this->exchange($code));
        self::assertResponseIsSuccessful();
        $original = $this->json();
        $refresh = ['grant_type' => 'refresh_token', 'client_id' => 'codex-cli', 'refresh_token' => $original['refresh_token']];

        $this->token($refresh + ['scope' => 'cash_transactions.read']);
        self::assertResponseStatusCodeSame(400);
        $this->token($refresh + ['scope' => 'accounts.read']);
        self::assertResponseIsSuccessful();
        $rotated = $this->json();
        self::assertNotSame($original['refresh_token'], $rotated['refresh_token']);
        self::assertSame('accounts.read', $rotated['scope']);
        $this->assertDeniedAccess($original['access_token'], 401);
        self::assertSame(['accounts.read'], static::getContainer()->get(McpAccess::class)->authenticate($this->bearer($rotated['access_token']))->scopes);

        $this->token($refresh);
        self::assertResponseStatusCodeSame(400);
        $this->assertDeniedAccess($rotated['access_token'], 401);
        $this->token(['refresh_token' => $rotated['refresh_token']] + $refresh);
        self::assertResponseStatusCodeSame(400);
    }

    public function testEntitlementsAreReadAgainAndOwnerCanRevokeWithCsrf(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $this->subscribe($company);
        $this->browser->loginUser($owner);
        $this->token($this->exchange($this->approve($this->consentUrl(), $company)));
        self::assertResponseIsSuccessful();
        $original = $this->json();
        $principal = static::getContainer()->get(McpAccess::class)->authenticate($this->bearer($original['access_token']));

        $db = $this->em()->getConnection();
        $db->executeStatement("UPDATE billing_company_subscriptions SET paid_until = '2022-01-31' WHERE company_id = ?", [$company->getId()]);
        $this->em()->clear();
        self::assertSame(['accounts.read'], static::getContainer()->get(McpAccess::class)->authenticate($this->bearer($original['access_token']))->scopes);
        $db->executeStatement('DELETE FROM billing_company_subscriptions WHERE company_id = ?', [$company->getId()]);
        $this->em()->clear();
        $this->assertDeniedAccess($original['access_token'], 403);
        $this->subscribe($company);

        $this->browser->request('GET', self::ORIGIN.'/integrations/mcp');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-mcp-connection="'.$principal->connectionId.'"]');
        $form = $this->browser->getCrawler()->selectButton('Отозвать')->form();
        $this->browser->request('POST', $form->getUri());
        self::assertResponseStatusCodeSame(403);
        $this->browser->submit($form);
        self::assertResponseRedirects('/integrations/mcp', 303);
        $this->assertDeniedAccess($original['access_token'], 401);
    }

    public function testProtocolRevocationClosesAccessAndRefreshImmediately(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $this->subscribe($company);
        $this->browser->loginUser($owner);
        $this->token($this->exchange($this->approve($this->consentUrl(), $company)));
        self::assertResponseIsSuccessful();
        $original = $this->json();

        $this->browser->request('POST', self::ORIGIN.'/oauth/revoke', ['client_id' => 'codex-cli', 'token' => $original['refresh_token']], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        self::assertResponseStatusCodeSame(200);
        $this->assertDeniedAccess($original['access_token'], 401);
        $this->token(['grant_type' => 'refresh_token', 'client_id' => 'codex-cli', 'refresh_token' => $original['refresh_token']]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testExpiredConnectionRejectsAccessAndRefresh(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $this->subscribe($company);
        $this->browser->loginUser($owner);
        $this->token($this->exchange($this->approve($this->consentUrl(), $company)));
        self::assertResponseIsSuccessful();
        $tokens = $this->json();
        $principal = static::getContainer()->get(McpAccess::class)->authenticate($this->bearer($tokens['access_token']));
        $this->em()->getConnection()->executeStatement(
            'UPDATE mcp_oauth_connections SET expires_at = CURRENT_TIMESTAMP - INTERVAL \'1 minute\' WHERE id = ?',
            [$principal->connectionId],
        );

        $this->assertDeniedAccess($tokens['access_token'], 401);
        $this->token(['grant_type' => 'refresh_token', 'client_id' => 'codex-cli', 'refresh_token' => $tokens['refresh_token']]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testOwnerCanCreateReplayAndConfirmDraftThroughMcpAndTheBrowser(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $companyId = (string) $company->getId();
        $this->subscribe($company);
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $project = new ProjectDirection(Uuid::uuid7()->toString(), $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $center = new FinancialResponsibilityCenter($companyId, FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        foreach ([$account, $project, $center, new FinancialResponsibilityCenterProject($companyId, $project, $center)] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $this->browser->loginUser($owner);
        $this->setClientSessionValue($this->browser, 'active_company_id', $companyId);
        $consent = $this->consentUrl(['scope' => 'accounts.read cash_transactions.read cash_drafts.create']);
        $this->token($this->exchange($this->approve($consent, $company)));
        self::assertResponseIsSuccessful();
        $accessToken = $this->json()['access_token'];

        $this->rpc([
            'method' => 'initialize',
            'id' => 1,
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'cash-test', 'version' => '1.0']],
        ], $accessToken);
        self::assertResponseStatusCodeSame(200);
        $sessionId = $this->browser->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotNull($sessionId);
        $this->rpc(['method' => 'notifications/initialized'], $accessToken, $sessionId);
        self::assertResponseStatusCodeSame(202);
        $this->rpc(['method' => 'tools/list', 'id' => 2], $accessToken, $sessionId);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['list_reference_items', 'list_cash_transactions', 'create_cash_draft'], array_column($this->json()['result']['tools'], 'name'));

        $draftCall = ['method' => 'tools/call', 'params' => ['name' => 'create_cash_draft', 'arguments' => [
            'externalId' => 'oauth-cash-e2e',
            'moneyAccountId' => $account->getId(),
            'date' => '2026-10-10',
            'direction' => 'OUTFLOW',
            'amount' => '1250.00',
        ]]];
        $factsCall = ['method' => 'tools/call', 'params' => ['name' => 'list_cash_transactions', 'arguments' => [
            'dateFrom' => '2026-10-10',
            'dateTo' => '2026-10-10',
        ]]];

        $this->rpc(['id' => 3] + $draftCall, $accessToken, $sessionId);
        self::assertResponseStatusCodeSame(200);
        $draft = $this->json()['result']['structuredContent'];
        self::assertSame('awaiting_confirmation', $draft['status']);
        $this->rpc(['id' => 4] + $draftCall, $accessToken, $sessionId);
        self::assertSame($draft, $this->json()['result']['structuredContent']);
        $this->rpc(['id' => 5] + $factsCall, $accessToken, $sessionId);
        self::assertSame([], $this->json()['result']['structuredContent']['items']);

        $path = '/finance/cash-transactions/mcp-drafts/'.$draft['draftId'];
        $page = $this->browser->request('GET', self::ORIGIN.$path);
        self::assertResponseIsSuccessful();
        $this->browser->submit($page->selectButton('Подтвердить оплату')->form(['paid' => '1']));
        self::assertResponseRedirects($path);

        $this->rpc(['id' => 6] + $factsCall, $accessToken, $sessionId);
        $facts = $this->json()['result']['structuredContent']['items'];
        self::assertCount(1, $facts);
        self::assertSame($account->getId(), $facts[0]['moneyAccountId']);
        self::assertSame('mcp', $facts[0]['importSource']);
        $this->rpc(['id' => 7] + $draftCall, $accessToken, $sessionId);
        self::assertSame($draft['draftId'], $this->json()['result']['structuredContent']['draftId']);
        self::assertSame('confirmed', $this->json()['result']['structuredContent']['status']);
    }

    /** @param array<string, mixed> $message */
    private function rpc(array $message, string $token, ?string $sessionId = null): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_AUTHORIZATION' => 'Bearer '.$token];
        if (null !== $sessionId) {
            $headers['HTTP_MCP_SESSION_ID'] = $sessionId;
            $headers['HTTP_MCP_PROTOCOL_VERSION'] = '2025-11-25';
        }
        $this->browser->request('POST', self::ORIGIN.'/mcp', server: $headers, content: json_encode(['jsonrpc' => '2.0'] + $message, \JSON_THROW_ON_ERROR));
    }

    private function assertDeniedAccess(string $token, int $status): void
    {
        try {
            static::getContainer()->get(McpAccess::class)->authenticate($this->bearer($token));
            self::fail('Expected the token to be denied.');
        } catch (HttpException $exception) {
            self::assertSame($status, $exception->getStatusCode());
            $this->lastChallenge = $exception->getHeaders()['WWW-Authenticate'] ?? '';
        }
    }

    private function bearer(string $token): Request
    {
        return Request::create(self::ORIGIN.'/mcp', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    /** @return array{User, Company} */
    private function ownerAndCompany(): array
    {
        $owner = UserBuilder::aUser()->withIndex(32764)->build();
        $company = CompanyBuilder::aCompany()->withIndex(32764)->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();

        return [$owner, $company];
    }

    private function subscribe(Company $company): void
    {
        $tariff = $this->em()->getRepository(Tariff::class)->findOneBy(['code' => TariffCode::START]);
        if (null === $tariff) {
            $tariff = TariffBuilder::aTariff()->withId(Uuid::uuid7()->toString())->build();
            $this->em()->persist($tariff);
        }
        $subscription = CompanySubscriptionBuilder::aSubscription()->forCompany((string) $company->getId())->onTariff($tariff)->paid('2022-01-01', '2099-01-01')->build();
        $this->em()->persist($subscription);
        $this->em()->flush();
        $this->em()->detach($subscription);
    }

    /** @param array<string, mixed> $override */
    private function start(array $override, string $suffix = ''): void
    {
        $this->browser->request('GET', self::ORIGIN.'/oauth/authorize?'.http_build_query($override + [
            'response_type' => 'code',
            'client_id' => 'codex-cli',
            'redirect_uri' => self::REDIRECT,
            'resource' => self::ORIGIN.'/mcp',
            'state' => 'secret-browser-state',
            'scope' => self::SCOPES,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]).$suffix);
    }

    /** @param array<string, string> $overrides */
    private function consentUrl(array $overrides = []): string
    {
        $this->start($overrides);
        self::assertResponseStatusCodeSame(303);
        $location = $this->browser->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringStartsWith('/integrations/mcp/authorize/', $location);
        self::assertStringNotContainsString('secret-browser-state', $location);

        return self::ORIGIN.$location;
    }

    private function approve(string $consent, Company $company): string
    {
        $this->browser->request('GET', $consent);
        self::assertResponseIsSuccessful();
        $transaction = basename($consent);
        $this->browser->request('POST', $consent, [
            'companyId' => $company->getId(),
            'decision' => 'approve',
            '_token' => $this->csrfToken($this->browser, 'mcp-consent-'.$transaction, $consent),
        ]);
        $location = $this->browser->getResponse()->headers->get('Location');
        if (null === $location) {
            return '';
        }
        self::assertSame(self::REDIRECT, strstr($location, '?', true));
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $callback);
        self::assertSame('secret-browser-state', $callback['state'] ?? null);
        self::assertSame(self::ORIGIN, $callback['iss'] ?? null);
        self::assertIsString($callback['code'] ?? null);

        return $callback['code'];
    }

    /** @return array<string, string> */
    private function exchange(string $code): array
    {
        return ['grant_type' => 'authorization_code', 'client_id' => 'codex-cli', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER];
    }

    /** @param array<string, mixed> $form */
    private function token(array $form): void
    {
        $this->browser->request('POST', self::ORIGIN.'/oauth/token', $form, [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
