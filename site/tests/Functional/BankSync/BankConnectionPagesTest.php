<?php

declare(strict_types=1);

namespace App\Tests\Functional\BankSync;

use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Message\SyncBankConnectionMessage;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\BankSync\StubBankAuthorizationProvider;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class BankConnectionPagesTest extends WebTestCaseBase
{
    private const TOKEN = 'functional-secret-bank-token';

    private KernelBrowser $client;
    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        StubBankAuthorizationProvider::reset();

        $this->client = static::createClient();
        $this->owner = $owner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('bank-pages-owner@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $this->company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($this->company);
        $this->em()->flush();

        $this->loginAs($owner);
    }

    public function testAlfaHasNoStaticTokenForm(): void
    {
        $crawler = $this->client->request('GET', '/bank-connections/');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/bank-connections/new"]'), 'Альфа подключается только входом через Alfa ID');

        $this->client->request('GET', '/bank-connections/new');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEditEnqueueAndDeleteOAuthConnection(): void
    {
        $connection = $this->persistOAuthConnection();
        $id = $connection->getId();
        $ciphertext = $this->connectionRow()['credentials_encrypted'];

        $crawler = $this->client->request('GET', sprintf('/bank-connections/%s/edit', $id));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="bank_connection[token]"]'), 'поля токена нет');
        self::assertCount(1, $crawler->filter('select[name="bank_connection[baseUrl]"][disabled]'), 'среда — только новым входом');

        $crawler = $this->client->request('GET', '/bank-connections/');
        $this->client->submit($crawler->selectButton('Запустить импорт сейчас')->form());
        self::assertResponseRedirects('/bank-connections/');
        // Ядро клиента перезагружается между запросами: транспорт — из контейнера этого запроса.
        /** @var InMemoryTransport $transport */
        $transport = $this->client->getContainer()->get('messenger.transport.async_sync');
        $sent = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), iterator_to_array($transport->getSent())),
            static fn (object $message): bool => $message instanceof SyncBankConnectionMessage,
        ));
        self::assertCount(1, $sent);
        self::assertSame($id, $sent[0]->connectionId);
        self::assertSame((string) $this->company->getId(), $sent[0]->companyId);

        $crawler = $this->client->request('GET', sprintf('/bank-connections/%s/edit', $id));
        $form = $crawler->selectButton('Сохранить')->form();
        $isActive = $form['bank_connection[isActive]'];
        self::assertInstanceOf(ChoiceFormField::class, $isActive);
        $isActive->untick();
        $this->client->submit($form);
        self::assertResponseRedirects('/bank-connections/');
        $edited = $this->connectionRow();
        self::assertSame('disabled', $edited['status']);
        self::assertSame('https://sandbox.alfabank.ru', $edited['base_url']);
        self::assertSame($ciphertext, $edited['credentials_encrypted']);

        $crawler = $this->client->request('GET', '/bank-connections/');
        $this->client->submit($crawler->filter(sprintf('form[action="/bank-connections/%s/delete"]', $id))->form());
        self::assertResponseRedirects('/bank-connections/');
        self::assertFalse($this->connectionRowOrFalse());
        self::assertSame(['oauth-refresh'], StubBankAuthorizationProvider::$revoked, 'токен отозван в банке');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast-body', 'Подключение удалено');
    }

    public function testCraftedTokenOrEnvironmentForAlfaIsRejected(): void
    {
        $connection = $this->persistOAuthConnection();
        $before = $this->connectionRow();
        $crawler = $this->client->request('GET', sprintf('/bank-connections/%s/edit', $connection->getId()));
        $token = $crawler->filter('input[name="bank_connection[_token]"]')->attr('value');

        $this->client->request('POST', sprintf('/bank-connections/%s/edit', $connection->getId()), ['bank_connection' => [
            '_token' => $token,
            'isActive' => '1',
            'token' => self::TOKEN,
        ]]);

        self::assertSame($before['credentials_encrypted'], $this->connectionRow()['credentials_encrypted'], 'лишнее поле формы не превращает OAuth в статический токен');
        $this->client->request('POST', '/bank-connections/new', ['bank_connection' => ['token' => self::TOKEN]]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($before['credentials_encrypted'], $this->connectionRow()['credentials_encrypted']);
    }

    public function testConsentExpiryAndReconnectAreShown(): void
    {
        $connection = $this->persistOAuthConnection();
        $connection->recordAuthorizedSubject('user-1');
        $connection->updateConsentExpiresAt(new \DateTimeImmutable('+10 days'));
        // Подключение — к песочнице: переподключить её может только администратор.
        $this->owner->setRoles(['ROLE_COMPANY_OWNER', 'ROLE_ADMIN']);
        $this->em()->flush();
        $this->loginAs($this->owner);

        $crawler = $this->client->request('GET', '/bank-connections/');

        self::assertSelectorTextContains('table', 'до '.(new \DateTimeImmutable('+10 days'))->format('d.m.Y'));
        self::assertSelectorTextContains('table', 'Согласие скоро истечёт');
        self::assertCount(1, $crawler->filter(sprintf('form[action="/bank-connections/oauth/start"] input[name="connection_id"][value="%s"]', $connection->getId())));
    }

    public function testReadOnlyMemberSeesPageButCannotCreate(): void
    {
        $readOnlyRole = new CompanyRole(Uuid::uuid4()->toString(), 'Только чтение финансов', [Module::FINANCE->value => AccessLevel::READ->value]);
        $memberUser = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('bank-pages-reader@example.test')
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($this->company)
            ->withUser($memberUser)
            ->withRole(CompanyMember::ROLE_OPERATOR)
            ->withAccessRole($readOnlyRole)
            ->build();
        foreach ([$readOnlyRole, $memberUser, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->loginAs($memberUser);

        $this->client->request('GET', '/bank-connections/');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/bank-connections/new', ['bank_connection' => ['token' => self::TOKEN]]);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->connectionRowOrFalse());
    }

    public function testForeignConnectionDeleteIs404AndNotRevoked(): void
    {
        $otherOwner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('bank-pages-other@example.test')->build();
        $otherCompany = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($otherOwner)->build();
        $this->em()->persist($otherOwner);
        $this->em()->persist($otherCompany);
        $this->em()->flush();
        $foreign = $this->persistOAuthConnection((string) $otherCompany->getId());

        $this->client->request('POST', sprintf('/bank-connections/%s/delete', $foreign->getId()), ['_token' => 'any']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], StubBankAuthorizationProvider::$revoked);
        self::assertNotFalse($this->em()->getConnection()->fetchOne('SELECT id FROM bank_sync_connection WHERE id = :id', ['id' => $foreign->getId()]));
    }

    private function persistOAuthConnection(?string $companyId = null): BankConnection
    {
        /** @var FieldEncryptionServiceInterface $encryption */
        $encryption = self::getContainer()->get(FieldEncryptionServiceInterface::class);
        $connection = BankConnectionBuilder::aConnection()
            ->withId(Uuid::uuid7()->toString())
            ->withCompanyId($companyId ?? (string) $this->company->getId())
            ->withBaseUrl('https://sandbox.alfabank.ru')
            ->withCredentials((new BankCredentialsCodec($encryption))->encryptOAuthTokens(new OAuthTokens('oauth-access', 'oauth-refresh', new \DateTimeImmutable('+1 hour'))))
            ->build();
        $this->em()->persist($connection);
        $this->em()->flush();

        return $connection;
    }

    private function loginAs(User $user): void
    {
        $this->client->loginUser($user);
        $this->setClientSessionValue($this->client, 'active_company_id', $this->company->getId());
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionRow(): array
    {
        $row = $this->connectionRowOrFalse();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function connectionRowOrFalse(): array|false
    {
        return $this->em()->getConnection()->fetchAssociative(
            'SELECT id, base_url, credentials_encrypted, credentials_key_version, status FROM bank_sync_connection WHERE company_id = :companyId',
            ['companyId' => $this->company->getId()],
        );
    }
}
