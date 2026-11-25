<?php

declare(strict_types=1);

namespace App\Tests\Functional\Balance\Controller;

use App\Balance\Application\SeedBalanceStructureAction;
use App\Balance\Entity\BalanceOperation;
use App\Balance\Entity\BalanceOperationLine;
use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Enum\BalanceDirection;
use App\Balance\Enum\BalanceOperationKind;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\Tests\Builders\Balance\BalanceAccessGrantBuilder;
use App\Tests\Builders\Balance\BalanceAccountBuilder;
use App\Tests\Builders\Balance\BalanceAuditEventBuilder;
use App\Tests\Builders\Balance\BalanceCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class BalanceLedgerUiTest extends WebTestCaseBase
{
    public function testAllScreensUseLegacyShellAndHighlightTheirSection(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $this->configure($client);
        [$asset, $passive] = $this->accounts($companyId);
        $this->postDocument($client, 'opening', '2026-08-01', [[$asset, 'increase', '100.00'], [$passive, 'increase', '100.00']]);
        $this->postDocument($client, 'operation', '2026-08-02', [[$asset, 'increase', '10.00'], [$passive, 'increase', '10.00']]);
        $documentId = (string) $this->em()->getConnection()->fetchOne('SELECT id FROM balance_operations WHERE company_id=? AND kind=?', [$companyId, 'operation']);
        $articleId = (string) $this->em()->getConnection()->fetchOne('SELECT article_id FROM balance_accounts WHERE company_id=? AND id=?', [$companyId, $asset]);
        // Выбранный пользователем новый интерфейс не меняет оболочку Баланса.
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('ui_mode', 'app'));
        foreach ([
            '/balance/' => 'Баланс',
            '/balance/accounts' => 'Счета',
            '/balance/accounts/new' => 'Счета',
            '/balance/accounts/'.$asset.'/edit' => 'Счета',
            '/balance/accounts/'.$asset.'/card' => 'Счета',
            '/balance/accounts/'.$asset.'/target' => 'Счета',
            '/balance/journal' => 'Документы',
            '/balance/documents/new' => 'Документы',
            '/balance/documents/'.$documentId => 'Документы',
            '/balance/documents/'.$documentId.'/reverse' => 'Документы',
            '/balance/statement' => 'Движения',
            '/balance/compare' => 'Сравнение',
            '/balance/periods' => 'Периоды',
            '/balance/setup' => 'Настройка',
            '/balance/access' => 'Права',
            '/balance/structure/' => 'Статьи',
            '/balance/structure/new' => 'Статьи',
            '/balance/structure/'.$articleId.'/edit' => 'Статьи',
            '/balance/articles/'.$articleId.'/card' => 'Статьи',
            '/balance/history/account/'.$asset => 'Счета',
            '/balance/history/article/'.$articleId => 'Статьи',
            '/balance/history/operation/'.$documentId => 'Документы',
        ] as $path => $section) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertSelectorExists('html[data-ui-mode="legacy"]', $path);
            self::assertSelectorExists('main[data-balance-root]', $path);
            self::assertSelectorExists('#sidebar-menu.d-lg-flex:not(.d-flex)', $path);
            self::assertSelectorTextSame('[data-balance-nav] [aria-current="page"]', $section, $path);
            if ('Документы' === $section) {
                self::assertSelectorTextSame('#navbar-balance .active', 'Документы', $path);
            }
            if ('Статьи' === $section) {
                self::assertSelectorNotExists('#navbar-balance a[href="/balance/structure/"]');
                self::assertSelectorExists('aside a.active[href="/balance/structure/"]', $path);
            }
        }
        $client->request('GET', '/balance/?date=2026-02-30');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('html[data-ui-mode="legacy"]');
        self::assertSelectorExists('main[data-balance-root] [role="alert"]');
    }

    public function testDateFiltersKeepTheirPurpose(): void
    {
        $client = static::createClient();
        $this->loginOwner($client);
        $client->request('GET', '/balance/?date=2026-08-15');
        self::assertInputValueSame('date', '2026-08-15');
        self::assertSelectorNotExists('input[name="from"]');
        self::assertSelectorNotExists('[data-period-presets]');
        $client->request('GET', '/balance/compare?from=2026-08-15&to=2026-08-01');
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('from', '2026-08-15');
        self::assertInputValueSame('to', '2026-08-01');
        self::assertSelectorTextSame('label[for="balance-from"]', 'Первая дата');
        self::assertSelectorTextSame('label[for="balance-to"]', 'Вторая дата');
        self::assertSelectorNotExists('[data-period-presets]');
        foreach (['/balance/statement', '/balance/journal'] as $path) {
            $client->request('GET', $path.'?from=2026-08-01&to=2026-08-31');
            self::assertResponseIsSuccessful();
            self::assertInputValueSame('from', '2026-08-01');
            self::assertInputValueSame('to', '2026-08-31');
            self::assertSelectorExists('[data-period-presets] button[type="button"][data-period-from][data-period-to]');
        }
    }

    public function testCardAndHistoryPaginationKeepsRouteIdsAndFilters(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $this->configure($client);
        [$asset, $passive] = $this->accounts($companyId);
        $this->postDocument($client, 'opening', '2026-08-01', [[$asset, 'increase', '100.00'], [$passive, 'increase', '100.00']]);
        $this->postDocument($client, 'operation', '2026-08-02', [[$asset, 'increase', '10.00'], [$passive, 'increase', '10.00']]);
        $this->postDocument($client, 'operation', '2026-08-03', [[$asset, 'increase', '20.00'], [$passive, 'increase', '20.00']]);
        $articleId = (string) $this->em()->getConnection()->fetchOne('SELECT article_id FROM balance_accounts WHERE company_id=? AND id=?', [$companyId, $asset]);
        foreach (['/balance/accounts/'.$asset.'/card', '/balance/articles/'.$articleId.'/card'] as $path) {
            $client->request('GET', $path.'?from=2026-08-01&to=2026-08-31&limit=1');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.table-responsive .table');
            $next = $client->getCrawler()->filter('[data-balance-root] a[aria-label="Вперёд"]')->link();
            self::assertSame($path, parse_url($next->getUri(), \PHP_URL_PATH));
            parse_str((string) parse_url($next->getUri(), \PHP_URL_QUERY), $query);
            self::assertSame(['from' => '2026-08-01', 'to' => '2026-08-31', 'limit' => '1', 'page' => '2'], $query);
            $client->click($next);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[data-balance-root]', 'Документ operation');
            self::assertInputValueSame('from', '2026-08-01');
            self::assertSelectorExists('[data-period-presets]');
        }
        for ($i = 0; $i < 51; ++$i) {
            $this->em()->persist(BalanceAuditEventBuilder::aBalanceAuditEvent()->withCompanyId($companyId)->withObjectId($asset)->build());
        }
        $this->em()->flush();
        $path = '/balance/history/account/'.$asset;
        $client->request('GET', $path.'?from=2026-08-01&to=2026-08-31');
        self::assertResponseIsSuccessful();
        $next = $client->getCrawler()->filter('[data-balance-root] a[aria-label="Вперёд"]')->link();
        self::assertSame($path, parse_url($next->getUri(), \PHP_URL_PATH));
        parse_str((string) parse_url($next->getUri(), \PHP_URL_QUERY), $query);
        self::assertSame(['from' => '2026-08-01', 'to' => '2026-08-31', 'page' => '2'], $query);
        $client->click($next);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-balance-root]', 'Показано с 51');
    }

    public function testBootstrapFormsAndDeleteConfirmationPreserveCsrfAndVersion(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $this->configure($client);
        $this->accounts($companyId);
        $client->request('GET', '/balance/accounts/new');
        self::assertSelectorExists('input.form-control[name="balance_account[name]"]');
        self::assertSelectorExists('select.form-select[name="balance_account[articleId]"]');
        self::assertSelectorExists('.form-check-input[name="balance_account[allowNegative]"]');
        self::assertSelectorTextContains('.form-text', 'После первого движения');
        $client->request('GET', '/balance/documents/new');
        $client->submitForm('Сохранить черновик', ['balance_document[reason]' => 'Удаляемый черновик']);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $trigger = $client->getCrawler()->filter('button[data-confirm-submit="Удалить черновик"]');
        self::assertCount(1, $trigger);
        self::assertSame('#balance-confirm', $trigger->attr('data-bs-target'));
        $action = $trigger->attr('data-confirm-action');
        self::assertNotNull($action);
        $token = $trigger->attr('data-confirm-token');
        self::assertNotEmpty($token);
        $fields = json_decode((string) $trigger->attr('data-confirm-fields'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($fields);
        self::assertSame(1, $fields['version']);
        self::assertSelectorExists('#balance-confirm[data-confirm-modal] [data-confirm-submit][disabled]');
        // В браузере эти параметры переносит готовый confirm_modal.js.
        $this->submitConfirmTrigger($client, $trigger);
        self::assertResponseRedirects('/balance/journal');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM balance_operations WHERE company_id=?', [$companyId]));
    }

    public function testOwnerCanReversePostedDocumentThroughForm(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $this->configure($client);
        [$asset, $passive] = $this->accounts($companyId);
        $this->postDocument($client, 'opening', '2026-08-01', [[$asset, 'increase', '100.00'], [$passive, 'increase', '100.00']]);
        $this->postDocument($client, 'operation', '2026-08-02', [[$asset, 'increase', '10.00'], [$passive, 'increase', '10.00']]);
        $documentId = (string) $this->em()->getConnection()->fetchOne('SELECT id FROM balance_operations WHERE company_id=? AND kind=?', [$companyId, 'operation']);
        $client->request('GET', '/balance/documents/'.$documentId.'/reverse');
        self::assertSelectorExists('textarea.form-control[name="balance_reversal[reason]"]');
        $client->submitForm('Провести сторно', ['balance_reversal[date]' => '2026-08-03', 'balance_reversal[reason]' => 'Исправление документа']);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-balance-root]', 'Исходный документ');
        $client->request('GET', '/balance/documents/'.$documentId);
        self::assertSelectorTextContains('.badge.bg-warning-lt', 'Сторнирован');
        self::assertSame('10000', (string) $this->em()->getConnection()->fetchOne('SELECT balance FROM balance_account_states WHERE company_id=? AND account_id=?', [$companyId, $asset]));
    }

    public function testOwnerCanEditAccountAndSaveMemberPermissions(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        [$asset] = $this->accounts($companyId);
        $client->request('GET', '/balance/accounts/'.$asset.'/edit');
        $client->submitForm('Сохранить', ['balance_account[name]' => 'Измененный счет']);
        self::assertResponseRedirects('/balance/accounts');
        self::assertSame('Измененный счет', $this->em()->getConnection()->fetchOne('SELECT name FROM balance_accounts WHERE company_id=? AND id=?', [$companyId, $asset]));
        $company = $this->em()->find(Company::class, $companyId);
        self::assertInstanceOf(Company::class, $company);
        $user = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('balance-writer@example.test')->build();
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Финансы', ['finance' => 'write'], $company);
        $member = CompanyMemberBuilder::aMember()->withCompany($company)->withUser($user)->withAccessRole($role)->build();
        foreach ([$user, $role, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $client->request('GET', '/balance/access');
        self::assertSelectorExists('input.form-check-input[name="balance_grant[prepare]"]');
        $client->submitForm('Сохранить права', ['balance_grant[userId]' => $user->getId(), 'balance_grant[prepare]' => true, 'balance_grant[post]' => true]);
        self::assertResponseRedirects('/balance/access');
        $client->followRedirect();
        self::assertSelectorTextContains('tbody', 'Да');
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
        $client->request('GET', '/balance/documents/new');
        self::assertResponseRedirects('/balance/setup');
        $client->request('GET', '/balance/access');
        self::assertResponseStatusCodeSame(403);
    }

    public function testJournalPaginationPreservesEveryFilter(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        [$asset] = $this->accounts($companyId);
        $authorId = (string) $this->em()->getConnection()->fetchOne('SELECT user_id FROM companies WHERE id=?', [$companyId]);
        for ($i = 1; $i <= 51; ++$i) {
            $document = new BalanceOperation($companyId, (string) $i, BalanceOperationKind::OPERATION, new \DateTimeImmutable('2026-08-10'), $authorId, 'pagination-'.$i, hash('sha256', (string) $i), 'Фильтруемый документ');
            $this->em()->persist($document);
            $this->em()->persist(new BalanceOperationLine($companyId, $document->getId(), $asset, BalanceDirection::INCREASE, '100'));
        }
        $this->em()->flush();
        $filters = ['from' => '2026-08-01', 'to' => '2026-08-31', 'search' => 'Фильтруемый', 'status' => 'draft', 'kind' => 'operation', 'author_id' => $authorId, 'account_id' => $asset];
        $client->request('GET', '/balance/journal?'.http_build_query($filters));
        self::assertResponseIsSuccessful();
        $next = $client->getCrawler()->filter('[data-balance-root] a[aria-label="Вперёд"]')->link();
        parse_str((string) parse_url($next->getUri(), \PHP_URL_QUERY), $query);
        self::assertSame($filters + ['page' => '2'], $query);
        $client->click($next);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Фильтруемый документ');
        self::assertSelectorTextContains('[data-balance-root]', 'Показано с 51');
    }

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
        self::assertSame(34, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM balance_articles WHERE company_id=?', [$companyId]));
    }

    public function testUninitializedBalanceDoesNotShowConfirmedZeroReport(): void
    {
        $client = static::createClient();
        $this->loginOwner($client);
        $client->request('GET', '/balance/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Учет не начат');
    }

    public function testAccountsPageAddsStandardChartForExistingStructure(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        self::assertTrue(self::getContainer()->get(SeedBalanceStructureAction::class)($companyId));

        $client->request('GET', '/balance/accounts');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Склады фулфилмента');
        self::assertSame(28, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM balance_accounts WHERE company_id=?', [$companyId]));
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
            self::assertSelectorTextContains('main', 'Фактическое начало периода учета: 01.08.2026');
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
        self::assertSelectorTextContains('main', "100\u{00A0}000,00");
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
        self::assertSelectorExists('[data-balance-root] .alert-danger');
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

    public function testInvalidCurrencyPrecisionPreservesDraftForCorrection(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $this->configure($client);
        [$asset] = $this->accounts($companyId);
        $client->request('GET', '/balance/documents/new');
        $values = $client->getCrawler()->selectButton('Сохранить черновик')->form()->getPhpValues();
        $values['balance_document']['reason'] = 'Сохранить введенное основание';
        $values['balance_document']['lines'] = [['accountId' => $asset, 'direction' => 'increase', 'amount' => '1.001']];
        $client->request('POST', '/balance/documents/new', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertInputValueSame('balance_document[lines][0][amount]', '1.001');
        self::assertInputValueSame('balance_document[date]', $values['balance_document']['date']);
        self::assertInputValueSame('balance_document[requestKey]', $values['balance_document']['requestKey']);
        self::assertSelectorTextContains('textarea[name="balance_document[reason]"]', 'Сохранить введенное основание');
        self::assertSelectorTextContains('main', 'Сумма должна быть положительной и иметь точность валюты учета.');
        $client->submitForm('Сохранить черновик', ['balance_document[lines][0][amount]' => '1.00']);
        self::assertResponseRedirects();
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM balance_operations WHERE company_id=? AND request_key=?', [$companyId, $values['balance_document']['requestKey']]));
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
        self::assertSelectorNotExists('[data-balance-nav] a[href="/balance/setup"]');
        self::assertSelectorNotExists('[data-balance-nav] a[href="/balance/access"]');
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
        self::assertSelectorTextContains('[data-balance-root] h1', 'Основной счет');
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

    public function testScreensFollowDesignSystem(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $this->configure($client);
        [$asset, $passive] = $this->accounts($companyId);
        $this->postDocument($client, 'opening', '2026-08-01', [[$asset, 'increase', '100000.00'], [$passive, 'increase', '100000.00']]);
        $this->postDocument($client, 'operation', '2026-08-10', [[$asset, 'decrease', '1500.00'], [$passive, 'decrease', '1500.00']]);

        $crawler = $client->request('GET', '/balance/journal');
        self::assertResponseIsSuccessful();
        self::assertSame('10.08.2026', trim($crawler->filter('tbody tr')->first()->filter('td')->eq(1)->text()));
        self::assertSelectorTextSame('tbody .badge.bg-success-lt', 'Проведен');
        self::assertSelectorTextSame('[data-balance-nav] [aria-current="page"]', 'Документы');

        $crawler = $client->request('GET', '/balance/accounts');
        self::assertSelectorTextSame('thead th.text-end', 'Остаток, ₽');
        self::assertSelectorTextSame('[data-balance-nav] [aria-current="page"]', 'Счета');
        // Девять разделов владельца: пять вкладок видны, остальные — в «Ещё».
        self::assertCount(5, $crawler->filter('[data-balance-nav] .nav-link:not(.dropdown-toggle)'));
        self::assertSame(['Сравнение', 'Периоды', 'Настройка', 'Права'], $crawler->filter('[data-balance-nav] .dropdown-menu a')->each(static fn ($a): string => trim($a->text())));

        $client->request('GET', '/balance/accounts/'.$asset.'/card?from=2026-08-01&to=2026-08-31');
        self::assertSelectorTextSame('[data-balance-nav] [aria-current="page"]', 'Счета');
        // Список движений: знак у изменения — U+2212 у уменьшения, плюс у увеличения.
        self::assertSelectorTextSame('tbody td.text-end', "\u{2212}1\u{00A0}500,00");

        $client->request('GET', '/balance/periods');
        self::assertSelectorTextSame('[data-balance-nav] .dropdown-toggle.active', 'Периоды');

        // Баланс — до копейки, без сокращения.
        $crawler = $client->request('GET', '/balance/?date=2026-08-31');
        self::assertSame("98\u{00A0}500,00\u{00A0}₽", trim($crawler->filter('[data-balance-value]')->first()->text()));
        self::assertSelectorNotExists('[data-balance-value].text-danger');
        self::assertSelectorTextSame('[data-balance-explanation]', 'Актив и пассив равны');
    }

    public function testMismatchIsRedToTheKopeckWithExplanation(): void
    {
        $client = static::createClient();
        $companyId = $this->loginOwner($client);
        $this->configure($client);
        [$asset, $passive] = $this->accounts($companyId);
        $this->postDocument($client, 'opening', '2026-08-01', [[$asset, 'increase', '100000.00'], [$passive, 'increase', '100000.00']]);
        // Проведённые строки неизменяемы через UI: расхождение в 40 копеек создаём в БД.
        $this->em()->getConnection()->executeStatement(
            'UPDATE balance_operation_lines SET amount = amount + 40 WHERE company_id = ? AND account_id = ?',
            [$companyId, $asset],
        );

        $crawler = $client->request('GET', '/balance/?date=2026-08-31');
        self::assertResponseIsSuccessful();
        $kpi = $crawler->filter('[data-balance-mismatch="true"]');
        self::assertCount(1, $kpi);
        self::assertStringContainsString('border-danger', (string) $kpi->attr('class'));
        self::assertSame("0,40\u{00A0}₽", trim($kpi->filter('[data-balance-value].text-danger')->text()));
        self::assertStringContainsString("Актив больше пассива на 0,40\u{00A0}₽", $kpi->filter('[data-balance-explanation].text-danger')->text());
    }

    /**
     * @param list<array{string, string, string}> $lines [счёт, направление, сумма]
     */
    private function postDocument(KernelBrowser $client, string $kind, string $date, array $lines): void
    {
        $client->request('GET', '/balance/documents/new');
        $values = $client->getCrawler()->selectButton('Сохранить черновик')->form()->getPhpValues();
        $values['balance_document']['kind'] = $kind;
        $values['balance_document']['date'] = $date;
        $values['balance_document']['reason'] = 'Документ '.$kind;
        $values['balance_document']['lines'] = array_map(
            static fn (array $line): array => ['accountId' => $line[0], 'direction' => $line[1], 'amount' => $line[2]],
            $lines,
        );
        $client->request('POST', '/balance/documents/new', $values);
        self::assertResponseRedirects();
        $client->followRedirect();
        $client->submitForm('Провести сохраненный документ');
        self::assertResponseRedirects();
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
