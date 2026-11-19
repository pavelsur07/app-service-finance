<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;

/** Загрузка изменённого дерева статей ДДС из JSON: предпросмотр и применение. */
final class CashflowCategoryImportControllerTest extends WebTestCaseBase
{
    private const COMPANY = '0193ffff-0000-7000-8000-0000000004a1';
    private const OPERATING = '0193ffff-0000-7000-8000-0000000004b1';
    private const RENT = '0193ffff-0000-7000-8000-0000000004b2';
    private const SALARY = '0193ffff-0000-7000-8000-0000000004b3';

    private KernelBrowser $client;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->owner = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY)->withOwner($this->owner)->build();
        $this->em()->persist($this->owner);
        $this->em()->persist($company);
        $operating = (new CashflowCategory(self::OPERATING, $company))->setName('Операционная деятельность')->markAsSystem(CashflowCategory::CODE_OPERATING);
        $rent = (new CashflowCategory(self::RENT, $company))->setName('Аренда')->setParent($operating)->setSort(10);
        $salary = (new CashflowCategory(self::SALARY, $company))->setName('Зарплата')->setParent($operating)->setSort(20);
        foreach ([$operating, $rent, $salary] as $category) {
            $this->em()->persist($category);
        }
        $this->em()->flush();
        $this->em()->clear();
        $this->client->loginUser($this->owner);
        $this->setClientSessionValue($this->client, 'active_company_id', self::COMPANY);
    }

    public function testIndexOffersJsonDropdownWithExportAndImport(): void
    {
        $crawler = $this->client->request('GET', '/cashflow-categories/');

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('[data-test="cashflow-category-json"] .dropdown-menu');
        self::assertSame(
            ['/cashflow-categories/export/json', '/cashflow-categories/import'],
            $menu->filter('a.dropdown-item')->each(static fn (Crawler $link): ?string => $link->attr('href')),
        );
    }

    public function testUnchangedExportHasNothingToApply(): void
    {
        $crawler = $this->upload($this->export());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="import-empty"]', 'Изменений нет');
        self::assertCount(0, $crawler->filter('form[data-test="import-apply"]'));
    }

    public function testPreviewShowsChangesAndApplyWritesThemOnce(): void
    {
        $payload = $this->export();
        $payload[0]['children'][0]['name'] = 'Аренда помещений';
        $payload[0]['children'][1]['children'][] = ['name' => 'Премии'];

        $crawler = $this->upload($payload);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Операционная деятельность / Зарплата / Премии', $crawler->filter('[data-test="import-created"]')->text());
        self::assertStringContainsString('Аренда → Аренда помещений', $crawler->filter('[data-test="import-updated"]')->text());
        self::assertSame('Аренда', $this->em()->getConnection()->fetchOne('SELECT name FROM cashflow_categories WHERE id = ?', [self::RENT]), 'Предпросмотр ничего не пишет');

        $form = $crawler->filter('form[data-test="import-apply"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/cashflow-categories/');

        self::assertSame('Аренда помещений', $this->em()->getConnection()->fetchOne('SELECT name FROM cashflow_categories WHERE id = ?', [self::RENT]));
        self::assertSame(self::SALARY, $this->em()->getConnection()->fetchOne('SELECT parent_id FROM cashflow_categories WHERE name = ?', ['Премии']));
        self::assertSame(4, $this->categoryCount());

        // Повторная отправка той же формы (двойной клик, «Назад») дублей не создаёт.
        $this->client->submit($form);
        self::assertResponseRedirects('/cashflow-categories/');
        self::assertSame(4, $this->categoryCount());
    }

    public function testSwapsCodesAndReversesParentAndChild(): void
    {
        $this->em()->getConnection()->executeStatement("UPDATE cashflow_categories SET system_code = CASE id WHEN ? THEN 'A' ELSE 'B' END, parent_id = CASE id WHEN ? THEN ?::uuid ELSE parent_id END WHERE id IN (?, ?)", [self::RENT, self::SALARY, self::RENT, self::RENT, self::SALARY]);
        $payload = $this->export();
        $rent = $payload[0]['children'][0];
        $salary = $rent['children'][0];
        $rent['children'] = [];
        $rent['code'] = 'B';
        $salary['code'] = 'A';
        $salary['children'] = [$rent];
        $payload[0]['children'] = [$salary];

        $this->client->submit($this->upload($payload)->filter('form[data-test="import-apply"]')->form());

        self::assertResponseRedirects('/cashflow-categories/');
        self::assertSame(
            [['id' => self::RENT, 'system_code' => 'B', 'parent_id' => self::SALARY], ['id' => self::SALARY, 'system_code' => 'A', 'parent_id' => self::OPERATING]],
            $this->em()->getConnection()->fetchAllAssociative('SELECT id, system_code, parent_id FROM cashflow_categories WHERE id IN (?, ?) ORDER BY id', [self::RENT, self::SALARY]),
        );
    }

    public function testAppliesTwoChangedSiblingsWhateverRowOrderInDatabase(): void
    {
        // Строка первой по sort статьи обновлена последней: порядок чтения без ORDER BY расходится с порядком дерева.
        $this->em()->getConnection()->executeStatement('UPDATE cashflow_categories SET description = ? WHERE id = ?', ['x', self::RENT]);
        $payload = $this->export();
        $payload[0]['children'][0]['name'] = 'Аренда помещений';
        $payload[0]['children'][1]['name'] = 'Оплата труда';

        $this->client->submit($this->upload($payload)->filter('form[data-test="import-apply"]')->form());

        self::assertResponseRedirects('/cashflow-categories/');
        self::assertSame(['Аренда помещений', 'Оплата труда'], $this->em()->getConnection()->fetchFirstColumn('SELECT name FROM cashflow_categories WHERE id IN (?, ?) ORDER BY name', [self::RENT, self::SALARY]));
    }

    public function testForgedNewIdOfForeignCategoryIsRefusedWithoutServerError(): void
    {
        $owner = $this->em()->find(User::class, $this->owner->getId());
        \assert($owner instanceof User);
        $other = CompanyBuilder::aCompany()->withId('0193ffff-0000-7000-8000-0000000004a2')->withOwner($owner)->build();
        $foreign = (new CashflowCategory('0193ffff-0000-7000-8000-0000000004c1', $other))->setName('Чужая');
        $this->em()->persist($other);
        $this->em()->persist($foreign);
        $this->em()->flush();
        $payload = $this->export();
        $payload[0]['children'][] = ['name' => 'Премии'];
        $form = $this->upload($payload)->filter('form[data-test="import-apply"]')->form();
        $form['new_ids'] = '0193ffff-0000-7000-8000-0000000004c1';

        $this->client->submit($form);

        self::assertResponseRedirects('/cashflow-categories/import');
        self::assertSame(3, $this->categoryCount());
        self::assertSame('Чужая', $this->em()->getConnection()->fetchOne('SELECT name FROM cashflow_categories WHERE id = ?', ['0193ffff-0000-7000-8000-0000000004c1']));
    }

    public function testApplyIsRejectedWhenTreeChangedAfterPreview(): void
    {
        $payload = $this->export();
        $payload[0]['children'][0]['name'] = 'Аренда помещений';
        $form = $this->upload($payload)->filter('form[data-test="import-apply"]')->form();

        $this->em()->getConnection()->executeStatement('UPDATE cashflow_categories SET name = ? WHERE id = ?', ['Аренда склада', self::RENT]);
        $this->client->submit($form);

        self::assertResponseRedirects('/cashflow-categories/import');
        self::assertSame('Аренда склада', $this->em()->getConnection()->fetchOne('SELECT name FROM cashflow_categories WHERE id = ?', [self::RENT]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'изменилось после предпросмотра');
    }

    public function testFileOfAnotherCompanyIsRejectedWithoutWriting(): void
    {
        $payload = $this->export();
        $payload[0]['children'][0]['id'] = '0193ffff-0000-7000-8000-00000000dead';
        $payload[0]['children'][1]['name'] = 'Оплата труда';

        $crawler = $this->upload($payload);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('не найдена среди статей компании', $crawler->filter('[data-test="import-errors"]')->text());
        self::assertCount(0, $crawler->filter('form[data-test="import-apply"]'));
        self::assertSame('Зарплата', $this->em()->getConnection()->fetchOne('SELECT name FROM cashflow_categories WHERE id = ?', [self::SALARY]));
    }

    public function testBrokenJsonIsRejected(): void
    {
        $crawler = $this->uploadRaw('{"name": ');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('не JSON', $crawler->filter('[data-test="import-errors"]')->text());
    }

    public function testReadOnlyMemberCannotImport(): void
    {
        $reader = UserBuilder::aUser()->withIndex(541)->withRoles(['ROLE_COMPANY_USER'])->build();
        $company = $this->em()->find(Company::class, self::COMPANY);
        \assert($company instanceof Company);
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Чтение', ['finance' => 'read'], $company);
        $member = CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($reader)->withRole(CompanyMember::ROLE_OPERATOR)->withAccessRole($role)->build();
        foreach ([$reader, $role, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->client->loginUser($reader);
        $this->setClientSessionValue($this->client, 'active_company_id', self::COMPANY);

        $crawler = $this->client->request('GET', '/cashflow-categories/');
        self::assertResponseIsSuccessful();
        self::assertSame(['/cashflow-categories/export/json'], $crawler->filter('[data-test="cashflow-category-json"] a.dropdown-item')->each(static fn (Crawler $link): ?string => $link->attr('href')));
        $this->client->request('GET', '/cashflow-categories/import');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/cashflow-categories/import/apply');
        self::assertResponseStatusCodeSame(403);
    }

    /** @return list<array<string, mixed>> */
    private function export(): array
    {
        $this->client->request('GET', '/cashflow-categories/export/json');
        self::assertResponseIsSuccessful();

        /** @var list<array<string, mixed>> $payload */
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);

        return $payload;
    }

    /** @param list<array<string, mixed>> $payload */
    private function upload(array $payload): Crawler
    {
        return $this->uploadRaw(json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    private function uploadRaw(string $content): Crawler
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cf-import');
        file_put_contents($path, $content);
        $form = $this->client->request('GET', '/cashflow-categories/import')->filter('form[data-test="import-upload"]')->form();
        $field = $form['import_file'];
        \assert($field instanceof FileFormField);
        $field->upload($path);

        return $this->client->submit($form);
    }

    private function categoryCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cashflow_categories WHERE company_id = ?', [self::COMPANY]);
    }
}
