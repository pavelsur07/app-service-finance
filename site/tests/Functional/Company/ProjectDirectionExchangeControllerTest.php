<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProjectDirectionExchangeControllerTest extends WebTestCaseBase
{
    private const INDEX = '/project-directions/';
    private const EXPORT = '/project-directions/export';
    private const IMPORT = '/project-directions/import';
    private const CONFIRM = '/project-directions/import/confirm';
    private const PROJECT_ID = '019abcd0-0000-7000-8000-000000000001';

    public function testExportPreviewConfirmAndRepeat(): void
    {
        $client = static::createClient();
        $owner = $this->createUser(851);
        $company = $this->createCompany($owner);
        $this->login($client, $owner, $company);
        $client->request('GET', self::INDEX);
        self::assertSelectorTextContains('a[href="'.self::EXPORT.'"]', 'Выгрузить JSON');
        self::assertSelectorTextContains('a[href="'.self::IMPORT.'"]', 'Загрузить JSON');

        $before = $this->export($client);
        self::assertCount(1, $before['projects']);
        $system = $before['projects'][0];
        self::assertArrayNotHasKey('systemCode', $system);
        self::assertNull($system['code']);
        self::assertNull($system['description']);
        self::assertNull($system['parentId']);

        $row = array_replace($this->row(), ['parentId' => $system['id'], 'description' => "Первая строка\nВторая строка"]);
        $changed = array_replace($system, ['name' => 'Общие продажи', 'sort' => 30]);
        $json = $this->json($company, [$row, $changed]);
        $preview = $this->preview($client, $json);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Новых проектов: 1');
        self::assertSelectorTextContains('body', 'Изменённых проектов: 1');
        self::assertSelectorTextContains('body', 'Без изменений: 0');
        $confirm = $preview->selectButton('Подтвердить загрузку')->form();
        self::assertSame($before, $this->export($client));

        $client->submit($confirm);
        self::assertResponseRedirects(self::INDEX);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Загрузка завершена');
        self::assertSelectorTextContains('tbody', 'CODE_1');

        $exported = $this->export($client);
        $expected = [$row, $changed];
        usort($expected, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        self::assertEquals($expected, $exported['projects']);

        $preview = $this->preview($client, $this->json($company, $exported['projects']));
        self::assertSelectorTextContains('body', 'Без изменений: 2');
        $client->submit($preview->selectButton('Подтвердить загрузку')->form());
        self::assertResponseRedirects(self::INDEX);
        self::assertSame($exported, $this->export($client));
    }

    public function testPreviewShowsInvalidJsonAndRowErrorsWithoutChangingProjects(): void
    {
        $client = static::createClient();
        $owner = $this->createUser(852);
        $company = $this->createCompany($owner);
        $other = $this->createCompany($owner);
        $this->login($client, $owner, $company);
        $before = $this->export($client);

        foreach ([
            ['{', 'Некорректный JSON'],
            [$this->json($other, [$this->row()]), 'другой компании'],
            [$this->json($company, [array_replace($this->row(), ['parentId' => '019abcd0-0000-7000-8000-000000000099'])]), 'Запись 1: родитель'],
        ] as [$json, $expected]) {
            $crawler = $this->preview($client, $json);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', $expected);
            self::assertCount(0, $crawler->selectButton('Подтвердить загрузку'));
        }
        self::assertSame($before, $this->export($client));
    }

    public function testPreviewRejectsTooManyContainersBeforeParsing(): void
    {
        $client = static::createClient();
        $owner = $this->createUser(860);
        $company = $this->createCompany($owner);
        $this->login($client, $owner, $company);
        $json = sprintf('{"version":1,"companyId":"%s","projects":[%s{}]}', $company->getId(), str_repeat('{"x":0},', 350_000));
        $preview = $this->preview($client, $json);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'JSON содержит слишком много элементов для предпросмотра.');
        self::assertCount(0, $preview->selectButton('Подтвердить загрузку'));
        self::assertCount(1, $this->export($client)['projects']);
    }

    public function testPreviewRejectsExcessivelyWideJsonBeforeReadingRows(): void
    {
        $client = static::createClient();
        $owner = $this->createUser(862);
        $company = $this->createCompany($owner);
        $this->login($client, $owner, $company);
        $json = sprintf('{"version":1,"companyId":"%s","projects":[%s0]}', $company->getId(), str_repeat('0,', 2_250_000));
        $preview = $this->preview($client, $json);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('JSON содержит слишком много элементов для предпросмотра.', $preview->filter('body')->text());
        self::assertCount(0, $preview->selectButton('Подтвердить загрузку'));
    }

    public function testLargeExportCanBeUploadedAgain(): void
    {
        $client = static::createClient();
        $owner = $this->createUser(861);
        $company = $this->createCompany($owner);
        $this->login($client, $owner, $company);
        $rows = [];
        $description = str_repeat('🟢', 300);
        for ($i = 1; $i <= 8000; ++$i) {
            $rows[] = array_replace($this->row(), [
                'id' => sprintf('019abcdf-0000-7000-8000-%012d', $i),
                'code' => null,
                'description' => $description,
            ]);
        }
        $json = $this->json($company, $rows);
        $preview = $this->preview($client, $json);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->submit($preview->selectButton('Подтвердить загрузку')->form());
        self::assertResponseRedirects(self::INDEX);

        $client->request('GET', self::EXPORT);
        self::assertResponseIsSuccessful();
        $exportedJson = (string) $client->getResponse()->getContent();
        self::assertGreaterThan(8 * 1024 * 1024, \strlen($exportedJson));
        $preview = $this->preview($client, $exportedJson);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Без изменений: 8001');
        $client->submit($preview->selectButton('Подтвердить загрузку')->form());
        self::assertResponseRedirects(self::INDEX);
    }

    public function testReadOnlyMemberCanExportButCannotImport(): void
    {
        $client = static::createClient();
        $owner = $this->createUser(853);
        $reader = $this->createUser(854);
        $company = $this->createCompany($owner);
        $this->addFinanceMember($company, $reader, AccessLevel::READ);
        $this->login($client, $reader, $company);

        $client->request('GET', self::INDEX);
        self::assertSelectorExists('a[href="'.self::EXPORT.'"]');
        self::assertSelectorNotExists('a[href="'.self::IMPORT.'"]');
        $this->export($client);
        foreach ([['GET', self::IMPORT], ['POST', self::IMPORT], ['POST', self::CONFIRM]] as [$method, $url]) {
            $client->request($method, $url);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testBothPostsRequireCsrfAndConfirmationRequiresTheOriginalSession(): void
    {
        $client = static::createClient();
        $owner = $this->createUser(855);
        $company = $this->createCompany($owner);
        $this->login($client, $owner, $company);
        $json = $this->json($company, [$this->row()]);
        $invalid = $this->preview($client, $json, 'invalid');
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $invalid->selectButton('Подтвердить загрузку'));
        $preview = $this->preview($client, $json);
        $form = $preview->selectButton('Подтвердить загрузку')->form();

        $data = $form->getPhpValues();
        $client->request('POST', self::CONFIRM, array_replace($data, ['_token' => 'invalid']));
        self::assertResponseStatusCodeSame(403);
        $originalCookies = $client->getCookieJar()->all();
        $client->restart();
        $this->login($client, $owner, $company);
        $data['_token'] = $this->csrfToken($client, 'project_direction_import_confirm', self::CONFIRM);
        $client->request('POST', self::CONFIRM, $data);
        self::assertResponseRedirects(self::IMPORT);
        self::assertCount(1, $this->export($client)['projects']);

        $client->restart();
        foreach ($originalCookies as $cookie) {
            $client->getCookieJar()->set($cookie);
        }
        $client->submit($form);
        self::assertResponseRedirects(self::INDEX);
        $client->submit($form);
        self::assertResponseRedirects(self::IMPORT);
        self::assertCount(2, $this->export($client)['projects']);
    }

    public function testAnotherCompanyWriterCannotUseThePreviewFromTheSameSession(): void
    {
        $client = static::createClient();
        $owner = $this->createUser(857);
        $writer = $this->createUser(858);
        $company = $this->createCompany($owner);
        $this->addFinanceMember($company, $writer, AccessLevel::WRITE);
        $this->login($client, $owner, $company);
        $form = $this->preview($client, $this->json($company, [$this->row()]))->selectButton('Подтвердить загрузку')->form();
        $sessionId = $client->getRequest()->getSession()->getId();

        $this->login($client, $writer, $company);
        $data = $form->getPhpValues();
        $data['_token'] = $this->csrfToken($client, 'project_direction_import_confirm', self::CONFIRM);
        $client->request('POST', self::CONFIRM, $data);

        self::assertSame($sessionId, $client->getRequest()->getSession()->getId());
        self::assertResponseRedirects(self::IMPORT);
        self::assertCount(1, $this->export($client)['projects']);
    }

    public function testNewPreviewReplacesPreviousPreviewWithoutConfirmingEitherFile(): void
    {
        $client = static::createClient();
        $owner = $this->createUser(859);
        $company = $this->createCompany($owner);
        $this->login($client, $owner, $company);
        $previous = $this->preview($client, $this->json($company, [$this->row()]))->selectButton('Подтвердить загрузку')->form();
        $row = array_replace($this->row(), ['name' => 'Обновлённый файл']);
        $latest = $this->preview($client, $this->json($company, [$row]))->selectButton('Подтвердить загрузку')->form();

        $client->submit($previous);
        self::assertSame(self::IMPORT, $client->getResponse()->headers->get('Location'));
        self::assertCount(1, $this->export($client)['projects']);

        $client->submit($latest);
        self::assertResponseRedirects(self::INDEX);
        self::assertContains($row, $this->export($client)['projects']);
    }

    public function testPreviewExpiresAndCannotBeConfirmedAfterCompanySwitchOrWrite(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $clock = new MockClock();
        static::getContainer()->set(ClockInterface::class, $clock);
        $owner = $this->createUser(856);
        $company = $this->createCompany($owner);
        $other = $this->createCompany($owner);
        $this->login($client, $owner, $company);
        $json = $this->json($company, [$this->row()]);

        $confirm = $this->preview($client, $json)->selectButton('Подтвердить загрузку')->form();
        $clock->sleep(901);
        $client->submit($confirm);
        self::assertResponseRedirects(self::IMPORT);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Предпросмотр недоступен или истёк');
        self::assertCount(1, $this->export($client)['projects']);

        $confirm = $this->preview($client, $json)->selectButton('Подтвердить загрузку')->form();
        $this->setClientSessionValue($client, 'active_company_id', (string) $other->getId());
        $client->submit($confirm);
        self::assertResponseRedirects(self::IMPORT);
        self::assertCount(1, $this->export($client)['projects']);

        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
        $confirm = $this->preview($client, $json)->selectButton('Подтвердить загрузку')->form();
        $client->request('GET', '/project-directions/new');
        $client->submitForm('Сохранить', ['project_direction[name]' => 'Обычный проект']);
        self::assertResponseRedirects(self::INDEX);
        $client->submit($confirm);
        self::assertResponseRedirects(self::IMPORT);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Справочник изменился');
        self::assertCount(2, $this->export($client)['projects']);
    }

    private function createUser(int $index): User
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function createCompany(User $owner): Company
    {
        $creator = static::getContainer()->get(CompanyOwnerMembershipCreator::class);
        $company = $creator->createCompany($owner, 'Компания '.Uuid::uuid7()->toString());
        $this->em()->flush();

        return $company;
    }

    private function addFinanceMember(Company $company, User $user, AccessLevel $level): void
    {
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Доступ к финансам', [Module::FINANCE->value => $level->value]);
        $member = CompanyMemberBuilder::aMember()->withCompany($company)->withUser($user)->withRole(CompanyMember::ROLE_OPERATOR)->withAccessRole($role)->build();
        $this->em()->persist($role);
        $this->em()->persist($member);
        $this->em()->flush();
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
    }

    private function preview(KernelBrowser $client, string $json, ?string $token = null): Crawler
    {
        $path = tempnam(sys_get_temp_dir(), 'project-json-');
        self::assertNotFalse($path);
        file_put_contents($path, $json);
        try {
            $crawler = $client->request('GET', self::IMPORT);
            $values = $crawler->selectButton('Предпросмотр')->form()->getPhpValues();
            if (null !== $token) {
                $values['project_direction_import']['_token'] = $token;
            }

            return $client->request('POST', self::IMPORT, $values, [
                'project_direction_import' => ['file' => new UploadedFile($path, 'projects.json', 'application/json', null, true)],
            ]);
        } finally {
            unlink($path);
        }
    }

    /**
     * @return array{version: int, companyId: string, projects: list<array<string, mixed>>}
     */
    private function export(KernelBrowser $client): array
    {
        $client->request('GET', self::EXPORT);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=project-directions.json');

        /** @var array{version: int, companyId: string, projects: list<array<string, mixed>>} $file */
        $file = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $file['version']);

        return $file;
    }

    /** @param list<array<string, mixed>> $projects */
    private function json(Company $company, array $projects): string
    {
        return json_encode(['version' => 1, 'companyId' => $company->getId(), 'projects' => $projects], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /** @return array{id: string, name: string, code: string, description: null, sort: int, parentId: null} */
    private function row(): array
    {
        return ['id' => self::PROJECT_ID, 'name' => 'Розница', 'code' => 'CODE_1', 'description' => null, 'sort' => 17, 'parentId' => null];
    }
}
