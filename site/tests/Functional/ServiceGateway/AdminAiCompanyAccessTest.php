<?php

declare(strict_types=1);

namespace App\Tests\Functional\ServiceGateway;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AdminAiCompanyAccessTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private User $admin;
    private Company $company;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->admin = UserBuilder::aUser()->withIndex(1)->withEmail('admin+1@example.test')->withRoles(['ROLE_ADMIN'])->build();
        $owner = UserBuilder::aUser()->withIndex(2)->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withName('ООО Кабинет')->withOwner($owner)->build();

        $em = $this->em();
        $em->persist($this->admin);
        $em->persist($owner);
        $em->persist($this->company);
        $em->flush();
        $em->clear();
    }

    public function testAdminEnablesAndDisablesAiForCabinetAndRepeatIsSafe(): void
    {
        $this->client->loginUser($this->admin, 'admin');

        self::assertSame('ИИ выкл', $this->toggle('/admin/users?page=1'));
        // Подключение и возврат на ту же страницу списка.
        self::assertResponseRedirects('/admin/users?page=1');
        $this->client->followRedirect();
        self::assertSame(1, $this->rows());
        self::assertStringContainsString('ИИ вкл', $this->badgeText());
        self::assertSame(
            (string) $this->admin->getId(),
            $this->db()->fetchOne('SELECT enabled_by_user_id::text FROM ai_company_access'),
        );

        // Повтор включения (двойная отправка) — та же одна строка.
        $this->post(true);
        self::assertSame(1, $this->rows());

        // В тестовом окружении сервис выключен: подключение видно с пометкой.
        self::assertSame('ИИ вкл · сервис выключен', $this->toggle('/admin/users'));
        self::assertSame(0, $this->rows());
        $this->client->request('GET', '/admin/users');
        self::assertStringContainsString('ИИ выкл', $this->badgeText());
    }

    public function testInvalidAccountFormRenderDoesNotShowWrongAiState(): void
    {
        $this->client->loginUser($this->admin, 'admin');
        $this->post(true);

        // Ошибка формы создания аккаунта рендерит список без данных ИИ — «выкл» у подключённого быть не должно.
        $crawler = $this->client->request('POST', '/admin/users/new-account', ['admin_account_create' => ['email' => '']]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $crawler->filter(sprintf('[data-ai-badge="%s"]', $this->company->getId())));
        self::assertStringContainsString('ООО Кабинет', (string) $this->client->getResponse()->getContent());
    }

    public function testInvalidCsrfIsRejected(): void
    {
        $this->client->loginUser($this->admin, 'admin');
        $this->client->request('POST', $this->url(), ['enabled' => '1', '_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->rows());
    }

    public function testNonAdminIsForbidden(): void
    {
        $user = UserBuilder::aUser()->withIndex(3)->build();
        $this->em()->persist($user);
        $this->em()->flush();
        $this->client->loginUser($user, 'admin');
        $this->client->request('POST', $this->url(), ['enabled' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->rows());
    }

    public function testUnknownCompanyIsNotFound(): void
    {
        $this->client->loginUser($this->admin, 'admin');
        $this->client->request('POST', '/admin/ai/companies/0192f0a0-0000-7000-8000-00000000dead/access', ['enabled' => '1']);

        self::assertResponseStatusCodeSame(404);
    }

    /** Нажать отметку «ИИ» кабинета на странице списка; вернуть её текст до нажатия. */
    private function toggle(string $listUrl): string
    {
        $crawler = $this->client->request('GET', $listUrl);
        $button = $crawler->filter(sprintf('[data-ai-badge="%s"]', $this->company->getId()));
        $text = trim(preg_replace('/\s+/u', ' ', $button->text()) ?? '');
        $this->client->submit($button->form());

        return $text;
    }

    private function post(bool $enabled): void
    {
        $crawler = $this->client->request('GET', '/admin/users');
        $form = $crawler->filter(sprintf('[data-ai-badge="%s"]', $this->company->getId()))->form();
        $this->client->request('POST', $this->url(), [
            'enabled' => $enabled ? '1' : '0',
            '_token' => $form->getValues()['_token'],
        ]);
        self::assertResponseRedirects();
    }

    private function badgeText(): string
    {
        $button = $this->client->getCrawler()->filter(sprintf('[data-ai-badge="%s"]', $this->company->getId()));

        return trim(preg_replace('/\s+/u', ' ', $button->text()) ?? '');
    }

    private function url(): string
    {
        return sprintf('/admin/ai/companies/%s/access', $this->company->getId());
    }

    private function rows(): int
    {
        return (int) $this->db()->fetchOne('SELECT count(*) FROM ai_company_access');
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }
}
