<?php

declare(strict_types=1);

namespace App\Tests\Functional\BankSync;

use App\BankSync\Entity\BankConnection;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\BankSync\StubBankAuthorizationProvider;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Вход в банк по OAuth против заглушки авторизационного сервера
 * (`StubBankAuthorizationProvider`, зарегистрирована в services_test.yaml).
 */
final class BankOAuthFlowTest extends WebTestCaseBase
{
    private const CALLBACK_URL = 'http://localhost/bank-connections/oauth/callback';

    private KernelBrowser $client;
    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->owner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('bank-oauth-owner@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $this->company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($this->owner)->build();
        $this->em()->persist($this->owner);
        $this->em()->persist($this->company);
        $this->em()->flush();

        $this->client->loginUser($this->owner);
        $this->switchCompany($this->company);
    }

    public function testFullCycleStoresEncryptedTokensForNewConnection(): void
    {
        $authorizationUrl = $this->startFromIndex('Подключить через Альфа-Банк');

        self::assertStringStartsWith(StubBankAuthorizationProvider::AUTHORIZE_URL.'?', $authorizationUrl);
        $query = $this->query($authorizationUrl);
        self::assertTrue(Uuid::isValid($query['state']));
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43}$/', $query['code_challenge']);
        self::assertSame(self::CALLBACK_URL, $query['redirect_uri']);

        $this->returnFromBank($query['state'], StubBankAuthorizationProvider::codeFor($authorizationUrl));

        self::assertResponseRedirects('/bank-connections/');
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('active', $rows[0]['status']);
        self::assertSame('https://baas.alfabank.ru', $rows[0]['base_url']);
        $raw = json_encode($rows, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(StubBankAuthorizationProvider::ACCESS_TOKEN, $raw);
        self::assertStringNotContainsString(StubBankAuthorizationProvider::REFRESH_TOKEN, $raw);

        $tokens = $this->codec()->oauthTokens($this->connection($rows[0]['id']));
        self::assertNotNull($tokens);
        self::assertSame(StubBankAuthorizationProvider::ACCESS_TOKEN, $tokens->accessToken);
        self::assertSame(StubBankAuthorizationProvider::REFRESH_TOKEN, $tokens->refreshToken);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-body', 'Банк подключён');
    }

    public function testReusedStateIsRejected(): void
    {
        $authorizationUrl = $this->startFromIndex('Подключить через Альфа-Банк');
        $state = $this->query($authorizationUrl)['state'];
        $code = StubBankAuthorizationProvider::codeFor($authorizationUrl);
        $this->returnFromBank($state, $code);
        self::assertCount(1, $this->rows());
        $this->client->followRedirect(); // показать и снять сообщение об успехе

        $this->returnFromBank($state, $code);

        self::assertResponseRedirects('/bank-connections/');
        self::assertCount(1, $this->rows());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-body', 'Сеанс входа в банк устарел');
    }

    public function testUnknownStateIsRejected(): void
    {
        $this->startFromIndex('Подключить через Альфа-Банк');

        $this->returnFromBank(Uuid::uuid4()->toString(), 'ok:anything');

        self::assertSame([], $this->rows());
    }

    public function testWrongCodeVerifierIsRejectedByBankAndNothingIsSaved(): void
    {
        $state = $this->query($this->startFromIndex('Подключить через Альфа-Банк'))['state'];

        // Код, выданный под другой challenge: S256(verifier сессии) с ним не сойдётся.
        $this->returnFromBank($state, 'ok:'.str_repeat('A', 43));

        self::assertSame([], $this->rows());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-body', 'Банк отклонил вход');
    }

    public function testDeniedByClientSavesNothing(): void
    {
        $state = $this->query($this->startFromIndex('Подключить через Альфа-Банк'))['state'];

        $this->client->request('GET', '/bank-connections/oauth/callback', ['state' => $state, 'error' => 'access_denied']);

        self::assertResponseRedirects('/bank-connections/');
        self::assertSame([], $this->rows());
    }

    public function testCallbackInAnotherCompanyIsRejected(): void
    {
        $authorizationUrl = $this->startFromIndex('Подключить через Альфа-Банк');
        $owner = $this->em()->find(User::class, $this->owner->getId());
        self::assertNotNull($owner);
        $other = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em()->persist($other);
        $this->em()->flush();
        $this->switchCompany($other);

        $this->returnFromBank($this->query($authorizationUrl)['state'], StubBankAuthorizationProvider::codeFor($authorizationUrl));

        self::assertSame([], $this->rows());
        self::assertSame([], $this->rows($other));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-body', 'другой компании');
    }

    public function testReauthorizationReplacesCredentialsOfTheSameConnection(): void
    {
        $codec = $this->codec();
        $connection = BankConnectionBuilder::aConnection()
            ->withId(Uuid::uuid7()->toString())
            ->withCompanyId((string) $this->company->getId())
            ->withBaseUrl('https://sandbox.alfabank.ru')
            ->withCredentials($codec->encryptStaticToken('old-token'))
            ->build();
        $connection->markNeedsReauth('refresh_rejected');
        $this->em()->persist($connection);
        $this->em()->flush();
        $before = $this->rows()[0];

        $authorizationUrl = $this->startFromIndex('Переподключить');
        $this->returnFromBank($this->query($authorizationUrl)['state'], StubBankAuthorizationProvider::codeFor($authorizationUrl));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame($connection->getId(), $rows[0]['id']);
        self::assertSame('active', $rows[0]['status']);
        self::assertSame('https://sandbox.alfabank.ru', $rows[0]['base_url'], 'переподключение — в ту же среду');
        self::assertNotSame($before['credentials_encrypted'], $rows[0]['credentials_encrypted']);
        self::assertSame(StubBankAuthorizationProvider::ACCESS_TOKEN, $codec->token($this->connection($connection->getId())));
    }

    public function testStartRequiresCsrfToken(): void
    {
        $this->client->request('POST', '/bank-connections/oauth/start', ['bank_code' => 'alfa', 'base_url' => 'https://baas.alfabank.ru']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testStartForForeignConnectionIs404(): void
    {
        $crawler = $this->client->request('GET', '/bank-connections/');
        $token = $crawler->filter('form[action="/bank-connections/oauth/start"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/bank-connections/oauth/start', ['_token' => $token, 'connection_id' => Uuid::uuid7()->toString()]);

        self::assertResponseStatusCodeSame(404);
    }

    private function startFromIndex(string $button): string
    {
        $crawler = $this->client->request('GET', '/bank-connections/');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton($button)->form());
        self::assertResponseStatusCodeSame(302);

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    private function returnFromBank(string $state, string $code): void
    {
        $this->client->request('GET', '/bank-connections/oauth/callback', ['state' => $state, 'code' => $code]);
    }

    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        /* @var array<string, string> $query */
        return $query;
    }

    private function switchCompany(Company $company): void
    {
        $this->setClientSessionValue($this->client, 'active_company_id', $company->getId());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(?Company $company = null): array
    {
        /* @var list<array<string, mixed>> */
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT id, base_url, status, credentials_encrypted FROM bank_sync_connection WHERE company_id = :companyId ORDER BY created_at',
            ['companyId' => ($company ?? $this->company)->getId()],
        );
    }

    private function connection(string $id): BankConnection
    {
        $this->em()->clear();
        $connection = $this->em()->find(BankConnection::class, $id);
        self::assertNotNull($connection);

        return $connection;
    }

    private function codec(): BankCredentialsCodec
    {
        /** @var FieldEncryptionServiceInterface $encryption */
        $encryption = self::getContainer()->get(FieldEncryptionServiceInterface::class);

        return new BankCredentialsCodec($encryption);
    }
}
