<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Integration\Marketplace\PlPreview\PlPreviewFixture;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PlPreviewRowsXlsxControllerTest extends WebTestCaseBase
{
    private const string URL = '/marketplace/pl-preview/rows-xlsx?dateFrom=2026-01-01&dateTo=2026-01-31';
    private const string WIDE_URL = '/marketplace/pl-preview/xlsx?dateFrom=2026-01-01&dateTo=2026-01-31&empty=1';

    public function testGuestIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', self::URL);

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testMemberWithoutMarketplaceAccessIsForbidden(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 511);
        $role = new CompanyRole(Uuid::uuid4()->toString(), 'Только финансы', [Module::FINANCE->value => AccessLevel::READ->value]);
        $member = UserBuilder::aUser()->withIndex(9511)->withEmail('pl-rows-member@example.test')->withRoles(['ROLE_COMPANY_USER'])->build();
        $this->em()->persist($role);
        $this->em()->persist($member);
        $this->em()->persist(CompanyMemberBuilder::aMember()
            ->withCompany($fixture->company)
            ->withUser($member)
            ->withRole(CompanyMember::ROLE_OPERATOR)
            ->withStatus(CompanyMember::STATUS_ACTIVE)
            ->withAccessRole($role)
            ->build());
        $this->em()->flush();
        $this->em()->clear();
        $this->login($client, $member, $fixture->company);

        $client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRowsMatchWideExportOfEachChannel(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 521);
        $tag = MarketplaceListingTagBuilder::aTag()->forCompanyId((string) $fixture->company->getId())->withName('Зима')->build();
        $this->em()->persist($tag);
        $this->em()->flush();
        self::getContainer()->get(ListingTagAssignmentRepository::class)
            ->assign((string) $fixture->company->getId(), [$fixture->wbA->getId(), $fixture->ozA->getId()], $tag->getId());
        $this->em()->clear();
        $this->login($client, $fixture->owner, $fixture->company);

        $client->request('GET', self::URL.'&empty=0');

        self::assertResponseIsSuccessful();
        $headers = $client->getResponse()->headers;
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $headers->get('Content-Type'));
        self::assertStringContainsString('pl-rows_2026-01-01_2026-01-31.xlsx', (string) $headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));

        $sheets = $this->readXlsx((string) $client->getInternalResponse()->getContent());
        self::assertSame(['Данные', 'Паспорт', 'Покрытие', 'Реклама', 'Предупреждения'], array_keys($sheets));

        $data = $sheets['Данные'];
        $fields = array_flip($data[0]);
        $byChannel = [];
        foreach (array_slice($data, 1) as $row) {
            $byChannel[$row[$fields['marketplace']]][$row[$fields['row_name']]][$row[$fields['tag_name']]] = $row[$fields['value']];
        }
        self::assertSame(['ozon', 'wildberries'], array_keys($byChannel));
        self::assertSame(1300.0, $byChannel['wildberries']['REV']['Итого']);

        foreach ($byChannel as $marketplace => $rows) {
            $client->request('GET', self::WIDE_URL.'&marketplace='.$marketplace);
            self::assertResponseIsSuccessful();
            self::assertSame($this->wideValues($this->readXlsx((string) $client->getInternalResponse()->getContent())['ОПиУ по тегам']), $rows, $marketplace);
        }

        $passport = array_column(array_slice($sheets['Паспорт'], 1), 1, 0);
        self::assertSame((float) (\count($data) - 1), $passport['rows_count']);
        self::assertSame('ozon;wildberries', $passport['marketplaces']);
        self::assertSame(0.0, $passport['check_channels_vs_all_REV']);
    }

    public function testExportMenuOffersBothFormatsWithCurrentFilter(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 551);
        $tag = MarketplaceListingTagBuilder::aTag()->forCompanyId((string) $fixture->company->getId())->withName('Зима')->build();
        $this->em()->persist($tag);
        $this->em()->flush();
        $tagId = $tag->getId();
        $this->em()->clear();
        $this->login($client, $fixture->owner, $fixture->company);

        $crawler = $client->request('GET', '/marketplace/pl-preview?dateFrom=2026-01-01&dateTo=2026-01-31&marketplace=wildberries&tags_set=1&tags[]='.$tagId);

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('[data-testid="pl-preview-export"]');
        self::assertSame('Скачать xlsx', trim($menu->filter('button.dropdown-toggle')->text()));
        self::assertSame(['Таблица как на экране', 'Строками для план-факта'], $menu->filter('.dropdown-item > span')->each(static fn ($s): string => trim($s->text())));

        $files = [];
        foreach (['pl-preview-xlsx' => '/marketplace/pl-preview/xlsx?', 'pl-preview-rows-xlsx' => '/marketplace/pl-preview/rows-xlsx?'] as $testId => $prefix) {
            $href = (string) $menu->filter('[data-testid="'.$testId.'"]')->attr('href');
            self::assertStringStartsWith($prefix, $href);
            parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
            self::assertSame(['dateFrom' => '2026-01-01', 'dateTo' => '2026-01-31', 'marketplace' => 'wildberries', 'tags_set' => '1', 'tags' => [$tagId]], $query);

            $client->request('GET', $href);
            self::assertResponseIsSuccessful();
            $files[] = (string) $client->getResponse()->headers->get('Content-Disposition');
        }
        self::assertStringContainsString('pl-preview_2026-01-01_2026-01-31.xlsx', $files[0]);
        self::assertStringContainsString('pl-rows_2026-01-01_2026-01-31.xlsx', $files[1]);
    }

    public function testNoExportMenuWhenPeriodIsWrong(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 561);
        $this->em()->clear();
        $this->login($client, $fixture->owner, $fixture->company);

        $client->request('GET', '/marketplace/pl-preview?dateFrom=2026-02-01&dateTo=2026-01-01');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="pl-preview-export"]');
    }

    public function testOtherCompanyGetsNothingOfThisOne(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 531);
        $this->em()->persist(MarketplaceListingTagBuilder::aTag()->forCompanyId((string) $fixture->company->getId())->withName('Чужой тег')->build());
        $owner = UserBuilder::aUser()->withIndex(931)->withEmail('pl-rows-stranger@example.test')->build();
        $stranger = CompanyBuilder::aCompany()->withIndex(931)->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($stranger);
        $this->em()->flush();
        $revenueId = (string) $fixture->revenue->getId();
        $this->em()->clear();
        $this->login($client, $owner, $stranger);

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $sheets = $this->readXlsx((string) $client->getInternalResponse()->getContent());
        $dump = (string) json_encode($sheets, \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString($revenueId, $dump);
        self::assertStringNotContainsString('Чужой тег', $dump);
        self::assertStringNotContainsString('wb_logistics', $dump);
        self::assertCount(1, $sheets['Данные'], 'ни каналов, ни данных — только шапка');
    }

    public function testWrongPeriodRedirectsToTheTabWithSameFilter(): void
    {
        $client = static::createClient();
        $fixture = new PlPreviewFixture($this->em(), 541);
        $this->em()->clear();
        $this->login($client, $fixture->owner, $fixture->company);

        $client->request('GET', '/marketplace/pl-preview/rows-xlsx?dateFrom=2026-02-01&dateTo=2026-01-01&marketplace=ozon');

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/marketplace/pl-preview?', $location);
        self::assertStringContainsString('marketplace=ozon', $location);
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Начало периода позже окончания');
    }

    /**
     * Лист «ОПиУ по тегам» широкой выгрузки → статья → колонка → значение.
     *
     * @param list<list<mixed>> $sheet
     *
     * @return array<string, array<string, mixed>>
     */
    private function wideValues(array $sheet): array
    {
        $values = [];
        $header = null;
        foreach ($sheet as $row) {
            if (null === $header) {
                $header = 'Статья' === ($row[0] ?? null) ? $row : null;
                continue;
            }
            $name = trim((string) $row[0], "\u{00A0}");
            foreach (\array_slice($header, 1, null, true) as $index => $label) {
                $values[$name][(string) $label] = $row[$index];
            }
        }

        return $values;
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function readXlsx(string $content): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pl_rows_test_');
        file_put_contents($path, $content);
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);
        $sheets = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    // Целые числа OpenSpout читает как int — приводим к float для сравнения.
                    $rows[] = array_map(static fn (mixed $v): mixed => \is_int($v) ? (float) $v : $v, $row->toArray());
                }
                $sheets[$sheet->getName()] = $rows;
            }
        } finally {
            $reader->close();
            unlink($path);
        }

        return $sheets;
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
