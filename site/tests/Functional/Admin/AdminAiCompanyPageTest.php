<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AdminAiCompanyPageTest extends WebTestCaseBase
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

    public function testConnectFromPageWithLookbackThenChangeLimits(): void
    {
        $this->client->loginUser($this->admin, 'admin');
        $crawler = $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ИИ выкл', $crawler->filter('[data-ai-state]')->text());

        $form = $crawler->filter('[data-ai-connect-form]')->form(['monthly_limit_rub' => '300', 'lookback_days' => '30']);
        $this->client->submit($form);
        self::assertResponseRedirects($this->url());
        self::assertSame(['300.00', 30], $this->settings());

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('[data-ai-limits-form]')->form(['monthly_limit_rub' => '1 000,50', 'lookback_days' => '7']));
        self::assertResponseRedirects($this->url());
        self::assertSame(['1000.50', 7], $this->settings());
    }

    public function testInvalidLimitShowsErrorWithoutChanges(): void
    {
        $this->connect();
        $this->client->loginUser($this->admin, 'admin');
        $crawler = $this->client->request('GET', $this->url());

        $this->client->submit($crawler->filter('[data-ai-limits-form]')->form(['monthly_limit_rub' => '0']));
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Лимит — число больше 0', (string) $this->client->getResponse()->getContent());
        self::assertSame(['500.00', 90], $this->settings());
    }

    public function testPageShowsSpendRunsAndExhaustedLimit(): void
    {
        $this->connect('10.00');
        $now = new \DateTimeImmutable();
        $this->db()->insert('ai_usage', [
            'id' => Uuid::uuid7()->toString(), 'task_id' => Uuid::uuid7()->toString(), 'company_id' => $this->company->getId(),
            'task_type' => 'categorize_transactions', 'status' => 'accepted', 'model' => 'deepseek/deepseek-flash',
            'input_tokens' => 1200, 'output_tokens' => 300, 'cost_rub' => '12.5000', 'completed_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $this->db()->insert('ai_run', [
            'id' => Uuid::uuid7()->toString(), 'company_id' => $this->company->getId(), 'task_type' => 'categorize_transactions',
            'ran_at' => $now->format('Y-m-d H:i:s'), 'requested' => 0, 'tasks' => 0, 'redispatched' => 0, 'expired' => 0,
            'skipped_reason' => 'budget_exceeded',
        ]);

        $this->client->loginUser($this->admin, 'admin');
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('12,50 ₽ из 10 ₽', $crawler->filter('[data-ai-spent]')->text());
        self::assertCount(1, $crawler->filter('[data-ai-exhausted]'));
        self::assertStringContainsString('лимит исчерпан', $crawler->filter('[data-ai-runs]')->text());

        $list = $this->client->request('GET', '/admin/users');
        self::assertCount(1, $list->filter(sprintf('[data-ai-exhausted-badge="%s"]', $this->company->getId())));
        self::assertCount(1, $list->filter(sprintf('[data-ai-page-link="%s"]', $this->company->getId())));
    }

    public function testNonAdminIsForbiddenAndUnknownCompanyIsNotFound(): void
    {
        $user = UserBuilder::aUser()->withIndex(3)->build();
        $this->em()->persist($user);
        $this->em()->flush();
        $this->client->loginUser($user, 'admin');
        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url().'/limits', ['monthly_limit_rub' => '1', 'lookback_days' => '7']);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->admin, 'admin');
        $this->client->request('GET', '/admin/ai/companies/0192f0a0-0000-7000-8000-00000000dead');
        self::assertResponseStatusCodeSame(404);
    }

    private function connect(string $limit = '500.00'): void
    {
        $this->db()->insert('ai_company_access', [
            'id' => Uuid::uuid7()->toString(),
            'company_id' => $this->company->getId(),
            'enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'monthly_limit_rub' => $limit,
        ]);
    }

    /** @return array{string, int} */
    private function settings(): array
    {
        $row = $this->db()->fetchAssociative('SELECT monthly_limit_rub, lookback_days FROM ai_company_access WHERE company_id = ?', [$this->company->getId()]);
        self::assertIsArray($row);

        return [(string) $row['monthly_limit_rub'], (int) $row['lookback_days']];
    }

    private function url(): string
    {
        return sprintf('/admin/ai/companies/%s', $this->company->getId());
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }
}
