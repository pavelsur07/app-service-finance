<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Изоляция данных компании в контроллерах Cash: сущность чужой компании и
 * невалидный id дают 404 (или «недоступно» во flash-сценариях), своя — открывается.
 *
 * Загрузка идёт через findOneByIdAndCompanyId(): чужая и несуществующая запись
 * неразличимы, и ни одна ветка не держится на ручной проверке после find().
 */
final class CashCompanyIsolationTest extends WebTestCaseBase
{
    private KernelBrowser $client;

    /** @var array<string, string> */
    private array $own = [];

    /** @var array<string, string> */
    private array $foreign = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        [$user, $company] = $this->createCompany($em, 'Своя');
        [, $otherCompany] = $this->createCompany($em, 'Чужая');

        $this->own = $this->createEntities($em, $company);
        $this->foreign = $this->createEntities($em, $otherCompany);
        $em->flush();

        $this->client->loginUser($user);
        $this->setClientSessionValue($this->client, 'active_company_id', $company->getId());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function scopedRouteProvider(): iterable
    {
        yield 'профиль импорта: edit' => ['GET', '/cash/import/file/profiles/%s/edit', 'profile'];
        yield 'профиль импорта: delete' => ['POST', '/cash/import/file/profiles/%s/delete', 'profile'];
        yield 'подключение банка: edit' => ['GET', '/bank-connections/%s/edit', 'connection'];
        yield 'подключение банка: delete' => ['POST', '/bank-connections/%s/delete', 'connection'];
        yield 'подключение банка: enqueue' => ['POST', '/bank-connections/%s/enqueue-import', 'connection'];
        yield 'транзакция: show' => ['GET', '/finance/cash-transactions/%s', 'transaction'];
        yield 'транзакция: edit' => ['GET', '/finance/cash-transactions/%s/edit', 'transaction'];
        yield 'транзакция: create-pnl-document' => ['POST', '/finance/cash-transactions/%s/create-pnl-document', 'transaction'];
        yield 'транзакция: delete' => ['POST', '/finance/cash-transactions/%s/delete', 'transaction'];
        yield 'транзакция: restore' => ['POST', '/finance/cash-transactions/%s/restore', 'transaction'];
        yield 'транзакция: create-from' => ['POST', '/finance/cash-transactions/%s/create-from', 'transaction'];
        yield 'план платежа: edit' => ['GET', '/finance/payment-calendar/%s/edit', 'plan'];
        yield 'план платежа: status' => ['POST', '/finance/payment-calendar/%s/status', 'plan'];
        yield 'план платежа: postpone' => ['POST', '/finance/payment-calendar/%s/postpone', 'plan'];
        yield 'счёт: edit' => ['GET', '/accounts/%s/edit', 'account'];
        yield 'статья ДДС: edit' => ['GET', '/cashflow-categories/%s/edit', 'category'];
        yield 'статья ДДС: delete' => ['POST', '/cashflow-categories/%s/delete', 'category'];
    }

    #[DataProvider('scopedRouteProvider')]
    public function testForeignCompanyEntityIsNotFound(string $method, string $path, string $entity): void
    {
        $this->client->request($method, sprintf($path, $this->foreign[$entity]));

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('scopedRouteProvider')]
    public function testInvalidIdIsNotFound(string $method, string $path, string $entity): void
    {
        $this->client->request($method, sprintf($path, 'not-a-uuid'));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ownPageProvider(): iterable
    {
        yield 'профиль импорта' => ['/cash/import/file/profiles/%s/edit', 'profile'];
        yield 'подключение банка' => ['/bank-connections/%s/edit', 'connection'];
        yield 'транзакция' => ['/finance/cash-transactions/%s', 'transaction'];
        yield 'план платежа' => ['/finance/payment-calendar/%s/edit', 'plan'];
        yield 'транзакция: edit' => ['/finance/cash-transactions/%s/edit', 'transaction'];
        yield 'счёт' => ['/accounts/%s/edit', 'account'];
        yield 'статья ДДС' => ['/cashflow-categories/%s/edit', 'category'];
    }

    /**
     * Без токена POST по своей сущности не доходит до действия, но и не 404:
     * маршрут существует, сущность найдена, отказ — на проверке CSRF.
     * Иначе «чужой → 404» прошёл бы и при опечатке в пути.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function ownPostRouteProvider(): iterable
    {
        foreach (self::scopedRouteProvider() as $name => [$method, $path, $entity]) {
            if ('POST' === $method) {
                yield $name => [$path, $entity];
            }
        }
    }

    #[DataProvider('ownPostRouteProvider')]
    public function testOwnPostRouteResolvesEntity(string $path, string $entity): void
    {
        $this->client->request('POST', sprintf($path, $this->own[$entity]), ['_token' => 'invalid']);

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(404, $status);
        self::assertLessThan(500, $status);
    }

    public function testOwnDeletedTransactionIsRestored(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $transaction = $em->find(CashTransaction::class, $this->own['transaction']);
        self::assertInstanceOf(CashTransaction::class, $transaction);
        $transaction->markDeleted(null);
        $em->flush();

        $this->client->request('POST', sprintf('/finance/cash-transactions/%s/restore', $this->own['transaction']), [
            '_token' => $this->csrfToken($this->client, 'tx_restore'.$this->own['transaction']),
        ]);

        self::assertResponseRedirects('/finance/cash-transactions/deleted');
        $em->clear();
        $restored = $em->find(CashTransaction::class, $this->own['transaction']);
        self::assertInstanceOf(CashTransaction::class, $restored);
        self::assertFalse($restored->isDeleted());
    }

    #[DataProvider('ownPageProvider')]
    public function testOwnCompanyEntityOpens(string $path, string $entity): void
    {
        $this->client->request('GET', sprintf($path, $this->own[$entity]));

        self::assertResponseIsSuccessful();
    }

    public function testForeignCreateFromAnswersJsonNotFound(): void
    {
        $this->client->request('POST', sprintf('/finance/cash-transactions/%s/create-from', $this->foreign['transaction']));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(
            ['error' => true, 'message' => 'Транзакция не найдена.'],
            json_decode((string) $this->client->getResponse()->getContent(), true),
        );
    }

    public function testForeignImportProfileCannotBeApplied(): void
    {
        $this->setClientSessionValue($this->client, 'cash_file_import', ['file_hash' => 'x']);

        $this->client->request('POST', '/cash/import/file/mapping/profile/apply', [
            '_token' => $this->csrfToken($this->client, 'cash_file_import_profile_apply'),
            'profile_id' => $this->foreign['profile'],
        ]);

        self::assertResponseRedirects('/cash/import/file/mapping');
        self::assertSame(['Профиль импорта не найден.'], $this->flashes('error'));
    }

    public function testImportProfileWithPrefixedUuidIsNotFound(): void
    {
        $this->setClientSessionValue($this->client, 'cash_file_import', ['file_hash' => 'x']);

        $this->client->request('POST', '/cash/import/file/mapping/profile/apply', [
            '_token' => $this->csrfToken($this->client, 'cash_file_import_profile_apply'),
            'profile_id' => 'urn:uuid:'.$this->own['profile'],
        ]);

        self::assertResponseRedirects('/cash/import/file/mapping');
        self::assertSame(['Профиль импорта не найден.'], $this->flashes('error'));
    }

    public function testBank1cCommitRejectsForeignAccountFromSession(): void
    {
        $this->setClientSessionValue($this->client, 'bank1c_import', [
            'preview' => [],
            'account_id' => $this->foreign['account'],
        ]);

        $this->client->request('POST', '/cash/import/bank1c/commit', [
            '_token' => $this->csrfToken($this->client, 'bank1c_import_commit'),
        ]);

        self::assertResponseRedirects('/cash/import/bank1c', 303);
        self::assertSame(['Выбранный счёт недоступен. Пожалуйста, повторите загрузку файла.'], $this->flashes('danger'));
    }

    public function testBank1cPreviewRejectsForeignAccountFromSession(): void
    {
        $this->setClientSessionValue($this->client, 'bank1c_import', [
            'preview' => [],
            'account_id' => $this->foreign['account'],
        ]);

        $this->client->request('GET', '/cash/import/bank1c/preview');

        self::assertResponseRedirects('/cash/import/bank1c', 303);
        self::assertSame(['Выбранный счёт недоступен.'], $this->flashes('danger'));
    }

    public function testPaymentCalendarIgnoresInvalidIdFilters(): void
    {
        $this->client->request('GET', '/finance/payment-calendar', [
            'account_id' => 'urn:uuid:'.$this->own['account'],
            'category_id' => 'not-a-uuid',
            'counterparty_id' => 'not-a-uuid',
        ]);

        self::assertResponseIsSuccessful();
    }

    /**
     * @return array{User, Company}
     */
    private function createCompany(EntityManagerInterface $em, string $name): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail(sprintf('isolation-%s@example.com', Uuid::uuid4()->toString()));
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName($name);

        $em->persist($user);
        $em->persist($company);

        return [$user, $company];
    }

    /**
     * @return array<string, string>
     */
    private function createEntities(EntityManagerInterface $em, Company $company): array
    {
        $prefix = 'Своя' === $company->getName() ? 'Своя' : 'Чужая';

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::CASH, $prefix.' касса', 'RUB');
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->withName($prefix.' статья')
            ->build();
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->build();
        $profile = new CashFileImportProfile(Uuid::uuid4()->toString(), $company, $prefix.' профиль');
        $connection = BankConnectionBuilder::aConnection()
            ->withId(Uuid::uuid7()->toString())
            ->withCompanyId((string) $company->getId())
            ->build();
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $company, $category, new \DateTimeImmutable('today'), '100.00');

        foreach ([$account, $category, $transaction, $profile, $connection, $plan] as $entity) {
            $em->persist($entity);
        }

        return [
            'account' => (string) $account->getId(),
            'category' => (string) $category->getId(),
            'transaction' => (string) $transaction->getId(),
            'profile' => $profile->getId(),
            'connection' => (string) $connection->getId(),
            'plan' => (string) $plan->getId(),
        ];
    }

    /**
     * @return list<mixed>
     */
    private function flashes(string $type): array
    {
        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return array_values($session->getFlashBag()->peek($type));
    }
}
