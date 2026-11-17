<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/** Блок «Консолидация» в настройках компании (cashflow-group-chart, этап 1b). */
final class ConsolidationGroupSettingsTest extends WebTestCaseBase
{
    private const HEAD = '0193eeee-0000-7000-8000-0000000000a1';
    private const MEMBER = '0193eeee-0000-7000-8000-0000000000a2';
    private const FOREIGN = '0193eeee-0000-7000-8000-0000000000b1';

    private KernelBrowser $client;
    private User $owner;
    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->owner = UserBuilder::aUser()->withEmail('group-owner@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $this->stranger = UserBuilder::aUser()->withIndex(2)->withEmail('group-stranger@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $this->em()->persist($this->owner);
        $this->em()->persist($this->stranger);
        $this->em()->persist(CompanyBuilder::aCompany()->withId(self::HEAD)->withName('Головная')->withOwner($this->owner)->build());
        $this->em()->persist(CompanyBuilder::aCompany()->withId(self::MEMBER)->withName('Дочерняя')->withOwner($this->owner)->build());
        $this->em()->persist(CompanyBuilder::aCompany()->withId(self::FOREIGN)->withName('Чужая')->withOwner($this->stranger)->build());
        $this->em()->flush();
        $this->em()->clear();
    }

    public function testOwnerEnablesGroupAndMemberSeesIt(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', sprintf('/company/%s/edit', self::HEAD));
        $block = $crawler->filter('#consolidation');
        self::assertStringContainsString('Дочерняя', $block->text());
        self::assertStringNotContainsString('Чужая', $block->text(), 'компании чужого владельца не предлагаются');

        $form = $crawler->selectButton('Сохранить группу')->form();
        $enabled = $form['enabled'];
        self::assertInstanceOf(ChoiceFormField::class, $enabled);
        $enabled->tick();
        $members = $form['members'];
        self::assertIsArray($members);
        self::assertInstanceOf(ChoiceFormField::class, $members[0]);
        $members[0]->tick();
        $this->client->submit($form);

        self::assertResponseRedirects(sprintf('/company/%s/edit#consolidation', self::HEAD));
        self::assertSame([self::HEAD => self::HEAD, self::MEMBER => self::HEAD], $this->rows());

        $crawler = $this->client->request('GET', sprintf('/company/%s/edit', self::MEMBER));
        $block = $crawler->filter('#consolidation')->text();
        self::assertStringContainsString('Входит в группу компании «Головная»', $block);
        self::assertCount(0, $crawler->filter('#consolidation form'), 'состав меняют в настройках головной компании');
    }

    public function testForeignCompanyInMembersIsRefused(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('POST', sprintf('/company/%s/consolidation', self::HEAD), [
            '_token' => $this->csrfToken($this->client, 'consolidation'.self::HEAD),
            'enabled' => '1',
            'members' => [self::FOREIGN],
        ]);

        self::assertResponseRedirects(sprintf('/company/%s/edit#consolidation', self::HEAD));
        $this->client->followRedirect();
        self::assertStringContainsString('не принадлежит', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->rows());
    }

    public function testNotOwnerGetsNotFound(): void
    {
        $this->client->loginUser($this->stranger);
        $this->client->request('POST', sprintf('/company/%s/consolidation', self::HEAD), [
            '_token' => $this->csrfToken($this->client, 'consolidation'.self::HEAD),
            'enabled' => '1',
            'members' => [],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->rows());
    }

    public function testInvalidCsrfIsRefused(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('POST', sprintf('/company/%s/consolidation', self::HEAD), ['_token' => 'bad', 'enabled' => '1']);

        self::assertResponseRedirects(sprintf('/company/%s/edit#consolidation', self::HEAD));
        self::assertSame([], $this->rows());
    }

    /** @return array<string, string> */
    private function rows(): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->em()->getConnection()->fetchAllKeyValue('SELECT company_id, head_company_id FROM company_consolidation_member ORDER BY company_id');

        return $rows;
    }
}
