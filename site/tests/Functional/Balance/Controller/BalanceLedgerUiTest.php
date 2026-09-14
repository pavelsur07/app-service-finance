<?php

declare(strict_types=1);

namespace App\Tests\Functional\Balance\Controller;

use App\Balance\Enum\BalanceCategoryType;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\Tests\Builders\Balance\BalanceAccessGrantBuilder;
use App\Tests\Builders\Balance\BalanceAccountBuilder;
use App\Tests\Builders\Balance\BalanceCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class BalanceLedgerUiTest extends WebTestCaseBase
{
    public function testGrantTableDisplaysReopeningPermission(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $grant = BalanceAccessGrantBuilder::aBalanceAccessGrant()->withCompanyId($companyId)->withReopenPeriods()->build();
        $this->em()->persist($grant);
        $this->em()->flush();
        $crawler = $client->request('GET', '/balance/access');
        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('tbody tr')->first()->filter('td'));
        self::assertSame('Да', trim($crawler->filter('tbody tr')->first()->filter('td')->eq(4)->text()));
    }

    public function testOwnerCanConfigureBookThroughSetupForm(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $client->request('GET', '/balance/setup');
        self::assertResponseIsSuccessful();
        $client->submitForm('Сохранить настройки', ['balance_setup[currency]' => 'RUB', 'balance_setup[startDate]' => '2026-08-01']);
        self::assertResponseRedirects('/balance/');
        $book = $this->em()->getConnection()->fetchAssociative('SELECT currency,start_date,initialized FROM balance_books WHERE company_id=?', [$companyId]);
        self::assertIsArray($book);
        self::assertSame('RUB', $book['currency']);
        self::assertSame('2026-08-01', $book['start_date']);
        self::assertFalse($book['initialized']);
    }

    public function testExistingCompanyCanCreateDefaultStructureExplicitly(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $client->request('GET', '/balance/setup');
        $client->submitForm('Создать базовую структуру');
        self::assertResponseRedirects('/balance/structure/');
        self::assertSame(9, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM balance_articles WHERE company_id=?', [$companyId]));
    }

    public function testUninitializedBalanceDoesNotShowConfirmedZeroReport(): void
    {
        $client = static::createClient();
        $this->loginOwner($client);
        $client->request('GET', '/balance/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Учет не начат');
    }

    public function testReadScreensRenderWithoutAnyAccounts(): void
    {
        $client = static::createClient();
        $this->loginOwner($client);
        foreach (['/balance/accounts', '/balance/journal', '/balance/statement', '/balance/compare', '/balance/periods', '/balance/access', '/balance/structure/'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    public function testLegacyIntegrationRouteIsGone(): void
    {
        $client = static::createClient();
        $this->loginOwner($client);
        $client->request('POST', '/balance/structure/33333333-3333-4333-8333-333333333333/link-money-accounts-total');
        self::assertResponseStatusCodeSame(410);
    }

    public function testOwnerCanSaveAndPostBalancedOpeningDocument(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $this->configure($client);
        [$asset, $passive] = $this->accounts($companyId);
        $client->request('GET', '/balance/documents/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('template[data-balance-lines-target="prototype"]');
        $values = $client->getCrawler()->selectButton('Сохранить черновик')->form()->getPhpValues();
        $values['balance_document']['reason'] = 'Открытие учета';
        $values['balance_document']['lines'] = [
            ['accountId' => $asset, 'direction' => 'increase', 'amount' => '100000.00'],
            ['accountId' => $passive, 'direction' => 'increase', 'amount' => '100000.00'],
        ];
        $client->request('POST', '/balance/documents/new', $values);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $client->submitForm('Провести сохраненный документ');
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Проведен');
        $client->request('GET', '/balance/?date=2026-08-01');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Актив');
        self::assertSelectorTextContains('main', 'Пассив');
        self::assertSelectorNotExists('.empty-title');
        foreach (['/balance/accounts/'.$asset.'/card', '/balance/statement?from=2026-08-01&to=2026-08-31', '/balance/compare?from=2026-08-01&to=2026-08-31'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
        foreach (['/balance/accounts/'.$asset.'/card', '/balance/statement'] as $path) {
            $client->request('GET', $path.'?from=2026-07-01&to=2026-08-31');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Фактическое начало периода учета: 2026-08-01');
            $client->request('GET', $path.'?from=2026-07-01&to=2026-07-31');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Учет не начат в выбранном периоде');
        }
        self::assertSame('10000000', (string) $this->em()->getConnection()->fetchOne('SELECT balance FROM balance_account_states WHERE company_id=? AND account_id=?', [$companyId, $asset]));
        $articleId = (string) $this->em()->getConnection()->fetchOne('SELECT article_id FROM balance_accounts WHERE company_id=? AND id=?', [$companyId, $asset]);
        $this->em()->getConnection()->update('balance_articles', ['is_visible' => false], ['company_id' => $companyId, 'id' => $articleId], ['is_visible' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
        $client->request('GET', '/balance/?date=2026-08-01');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/balance/articles/'.$articleId.'/card"]');
        self::assertSelectorTextContains('main', '100 000,00');
    }

    public function testCategoryFormRejectsInvalidCsrf(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $client->request('GET', '/balance/structure/new');
        $values = $client->getCrawler()->selectButton('Сохранить')->form()->getPhpValues();
        $values['balance_category_form']['name'] = 'Не сохранять';
        $values['balance_category_form']['_token'] = 'invalid';
        $client->request('POST', '/balance/structure/new', $values);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.field-helper.error');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM balance_articles WHERE company_id=?', [$companyId]));
    }

    public function testAccountFormCreatesSeparateAccountUnderTerminalArticle(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $article = BalanceCategoryBuilder::aBalanceCategory()->withCompanyId($companyId)->withCode('MONEY')->build();
        $article->setKind('article');
        $this->em()->persist($article);
        $this->em()->flush();
        $client->request('GET', '/balance/accounts/new');
        self::assertResponseIsSuccessful();
        $client->submitForm('Сохранить', ['balance_account[articleId]' => $article->getId(), 'balance_account[name]' => 'Банк', 'balance_account[code]' => 'BANK']);
        self::assertResponseRedirects('/balance/accounts');
        self::assertSame($article->getId(), $this->em()->getConnection()->fetchOne('SELECT article_id FROM balance_accounts WHERE company_id=? AND code=?', [$companyId, 'BANK']));
    }

    public function testTargetCorrectionCanRefreshTheSameDraft(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $this->configure($client);
        [$asset, $passive] = $this->accounts($companyId);
        $client->request('GET', '/balance/documents/new');
        $client->submitForm('Сохранить черновик', ['balance_document[reason]' => 'Нулевое открытие']);
        $client->followRedirect();
        $client->submitForm('Провести сохраненный документ', ['confirm_zero' => '1']);
        self::assertResponseRedirects();
        $client->request('GET', '/balance/accounts/'.$asset.'/target');
        $values = $client->getCrawler()->selectButton('Подготовить корректировку')->form()->getPhpValues();
        $values['balance_target']['target'] = '100.00';
        $values['balance_target']['reason'] = 'Корректировка остатка';
        $values['balance_target']['lines'] = [['accountId' => $passive, 'direction' => 'increase', 'amount' => '100.00']];
        $client->request('POST', '/balance/accounts/'.$asset.'/target', $values);
        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $client->clickLink('Обновить расчет остатка');
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('balance_target[target]', '100.00');
        self::assertInputValueSame('balance_target[lines][0][amount]', '100.00');
        $client->submitForm('Подготовить корректировку');
        self::assertResponseRedirects($location);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM balance_operations WHERE company_id=?', [$companyId]));
    }

    public function testSparseInvalidDraftKeepsNextRowIndexUnique(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $this->configure($client);
        [$asset, $passive] = $this->accounts($companyId);
        $client->request('GET', '/balance/documents/new');
        $values = $client->getCrawler()->selectButton('Сохранить черновик')->form()->getPhpValues();
        $values['balance_document']['lines'] = [
            0 => ['accountId' => $asset, 'direction' => 'increase', 'amount' => ''],
            2 => ['accountId' => $passive, 'direction' => 'increase', 'amount' => '100.00'],
        ];
        $client->request('POST', '/balance/documents/new', $values);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-balance-lines-index-value="3"]');
        self::assertSelectorExists('[name="balance_document[lines][0][amount]"]');
        self::assertSelectorExists('[name="balance_document[lines][2][amount]"]');
    }

    public function testRecoveryRequiresCsrf(): void
    {
        $client = static::createClient();
        $this->loginOwner($client);
        $client->request('GET', '/balance/accounts');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/balance/accounts/rebuild"]');
        $client->request('POST', '/balance/accounts/rebuild', ['reason' => 'Проверка токена', '_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAccountsRejectInvalidPagination(): void
    {
        $client = static::createClient();
        $this->loginOwner($client);
        $client->request('GET', '/balance/accounts?limit=201');
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/balance/accounts?page=999');
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidDateReturnsValidationError(): void
    {
        $client = static::createClient();
        $this->loginOwner($client);
        $client->request('GET', '/balance/?date=2026-02-30');
        self::assertResponseStatusCodeSame(422);
    }

    public function testReadOnlyMemberCanViewDraftButCannotPrepareOrPost(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $this->configure($client);
        $client->request('GET', '/balance/documents/new');
        $client->submitForm('Сохранить черновик', ['balance_document[reason]' => 'Черновик для проверки доступа']);
        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        $company = $this->em()->find(Company::class, $companyId);
        self::assertInstanceOf(Company::class, $company);
        $memberUser = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('balance-reader@example.test')->build();
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Только просмотр', ['finance' => 'read'], $company);
        $member = CompanyMemberBuilder::aMember()->withCompany($company)->withUser($memberUser)->withAccessRole($role)->build();
        foreach ([$memberUser, $role, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
        $client->request('GET', $location);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Черновик для проверки доступа');
        self::assertSelectorNotExists('form[action$="/post"]');
        $client->request('GET', '/balance/documents/new');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/balance/periods');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/balance/periods');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $location.'/post');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTargetCorrectionDisplaysTheRequestedAccount(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $this->configure($client);
        [$accountId, $otherAccountId] = $this->accounts($companyId);
        foreach ([$accountId => '12345', $otherAccountId => '67890'] as $id => $balance) {
            $this->em()->getConnection()->insert('balance_account_states', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'account_id' => $id, 'balance' => $balance, 'journal_version' => 0, 'updated_at' => '2026-08-01 00:00:00']);
        }
        $client->request('GET', '/balance/accounts/'.$accountId.'/target');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Основной счет');
        self::assertInputValueSame('balance_target[target]', '123.45');
    }

    public function testForeignCompanyAccountIsNotExposed(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        [$account] = $this->accounts(Uuid::uuid7()->toString());
        $client->request('GET', '/balance/accounts/'.$account.'/edit');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM balance_accounts WHERE company_id=?', [$companyId]));
    }

    /** @return array{string,string} */
    private function accounts(string $companyId): array
    {
        $assetArticle = BalanceCategoryBuilder::aBalanceCategory()->withCompanyId($companyId)->withName('Деньги')->build();
        $assetArticle->setKind('article');
        $passiveArticle = BalanceCategoryBuilder::aBalanceCategory()->withIndex(2)->withCompanyId($companyId)->withName('Капитал')->withType(BalanceCategoryType::PASSIVE)->build();
        $passiveArticle->setKind('article');
        $asset = BalanceAccountBuilder::aBalanceAccount()->withCompanyId($companyId)->withArticleId($assetArticle->getId())->build();
        $passive = BalanceAccountBuilder::aBalanceAccount()->withCompanyId($companyId)->withArticleId($passiveArticle->getId())->withCode('EQUITY')->withName('Внесенный капитал')->build();
        foreach ([$assetArticle, $passiveArticle, $asset, $passive] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        return [$asset->getId(), $passive->getId()];
    }

    private function configure(KernelBrowser $client): void
    {
        $client->request('GET', '/balance/setup');
        $client->submitForm('Сохранить настройки', ['balance_setup[currency]' => 'RUB', 'balance_setup[startDate]' => '2026-08-01']);
        self::assertResponseRedirects('/balance/');
    }

    private function loginOwner(KernelBrowser $client): string
    {
        $this->resetDb();
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $id = $company->getId();
        self::assertNotNull($id);

        return $id;
    }
}
