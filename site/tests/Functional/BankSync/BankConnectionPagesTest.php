<?php

declare(strict_types=1);

namespace App\Tests\Functional\BankSync;

use App\BankSync\Message\SyncBankConnectionMessage;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class BankConnectionPagesTest extends WebTestCaseBase
{
    private const TOKEN = 'functional-secret-bank-token';

    private KernelBrowser $client;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $owner = UserBuilder::aUser()
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

    public function testCreateEditEnqueueAndDeleteConnection(): void
    {
        $crawler = $this->client->request('GET', '/bank-connections/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Создать')->form([
            'bank_connection[baseUrl]' => 'https://sandbox.alfabank.ru',
            'bank_connection[token]' => self::TOKEN,
        ]));
        self::assertResponseRedirects('/bank-connections/');

        $row = $this->connectionRow();
        self::assertSame('https://sandbox.alfabank.ru', $row['base_url']);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($row, \JSON_THROW_ON_ERROR));
        $ciphertext = $row['credentials_encrypted'];

        $this->client->request('GET', '/bank-connections/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Альфа-Банк');
        self::assertStringNotContainsString(self::TOKEN, (string) $this->client->getResponse()->getContent());

        // Пустой ключ при редактировании оставляет прежний.
        $crawler = $this->client->request('GET', sprintf('/bank-connections/%s/edit', $row['id']));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::TOKEN, (string) $this->client->getResponse()->getContent());
        $this->client->submit($crawler->selectButton('Сохранить')->form([
            'bank_connection[baseUrl]' => 'https://baas.alfabank.ru',
            'bank_connection[token]' => '',
        ]));
        self::assertResponseRedirects('/bank-connections/');
        $edited = $this->connectionRow();
        self::assertSame('https://baas.alfabank.ru', $edited['base_url']);
        self::assertSame($ciphertext, $edited['credentials_encrypted']);

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
        self::assertSame($row['id'], $sent[0]->connectionId);
        self::assertSame((string) $this->company->getId(), $sent[0]->companyId);

        $crawler = $this->client->request('GET', '/bank-connections/');
        $this->client->submit($crawler->filter(sprintf('form[action="/bank-connections/%s/delete"]', $row['id']))->form());
        self::assertResponseRedirects('/bank-connections/');
        self::assertFalse($this->connectionRowOrFalse());
    }

    public function testNewConnectionRequiresToken(): void
    {
        $crawler = $this->client->request('GET', '/bank-connections/new');
        $this->client->submit($crawler->selectButton('Создать')->form([
            'bank_connection[token]' => '   ',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Укажите API ключ.', (string) $this->client->getResponse()->getContent());
        self::assertFalse($this->connectionRowOrFalse());
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
