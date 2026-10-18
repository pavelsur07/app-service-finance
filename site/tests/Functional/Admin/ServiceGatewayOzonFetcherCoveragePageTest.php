<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\ServiceGateway\ServiceCoverageRowBuilder;
use App\Tests\Builders\ServiceGateway\ServiceCoverageSnapshotBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;

final class ServiceGatewayOzonFetcherCoveragePageTest extends WebTestCaseBase
{
    use ClockSensitiveTrait;

    private const URL = '/admin/services/ozon-fetcher/coverage';

    /**
     * @param list<string> $roles
     */
    private function client(array $roles = ['ROLE_ADMIN']): KernelBrowser
    {
        self::mockTime(new \DateTimeImmutable('2026-10-18 09:10:00', new \DateTimeZone('Europe/Moscow')));
        $client = static::createClient();
        $user = UserBuilder::aUser()->withEmail('admin@example.test')->withRoles($roles)->build();
        $this->em()->persist($user);
        $this->em()->flush();
        $client->loginUser($user, 'admin');

        return $client;
    }

    public function testAdminSeesHeatmapFromReadModel(): void
    {
        $client = $this->client();
        $this->em()->persist(ServiceCoverageSnapshotBuilder::aSnapshot()->withRefreshedAt(new \DateTimeImmutable('2026-10-18 09:05:00'))->build());
        $this->em()->persist(ServiceCoverageRowBuilder::aRow()->withPeriodKey('2026-10-15')->withCounts(4, 3, pending: 1)->build());
        $this->em()->persist(ServiceCoverageRowBuilder::aRow()->withPeriodKey('2026-10-16')->withCounts(4, 3, failedPermanent: 1)->build());
        $this->em()->persist(ServiceCoverageRowBuilder::aRow()->withPeriodKey('2026-10-17')->build());
        $this->em()->flush();
        $this->em()->clear();

        $crawler = $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSame('Покрытие Сервис GO', trim($crawler->filter('h1.wz-title')->getNode(0)?->firstChild->textContent ?? ''));
        self::assertSame('18.10.2026 09:05', $crawler->filter('[data-coverage-refreshed]')->text());
        self::assertCount(0, $crawler->filter('[data-coverage-stale]'));
        self::assertCount(4, $crawler->filter('[data-coverage-row]'));
        self::assertCount(1, $crawler->filter('[data-coverage-cell="ok"]'));
        self::assertCount(1, $crawler->filter('[data-coverage-cell="partial"]'));
        self::assertCount(1, $crawler->filter('[data-coverage-cell="err"]'));
        self::assertStringContainsString('Загружено: 3 из 4 кабинетов', (string) $crawler->filter('[data-coverage-cell="partial"]')->attr('title'));
        self::assertSame('3/4', trim($crawler->filter('[data-coverage-cell="partial"]')->text()));
        self::assertSame('Октябрь 2026', $crawler->filter('.page-tab.is-active')->text());
        self::assertCount(3, $crawler->filter('.page-tabs .page-tab'));
        self::assertCount(1, $crawler->filter('.sb-item.is-active[href="'.self::URL.'"]'));
    }

    public function testStaleSnapshotIsMarked(): void
    {
        $client = $this->client();
        $this->em()->persist(ServiceCoverageSnapshotBuilder::aSnapshot()->withRefreshedAt(new \DateTimeImmutable('2026-10-18 08:00:00'))->build());
        $this->em()->flush();

        $crawler = $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-coverage-stale]'));
    }

    public function testEmptyReadModelShowsEmptyState(): void
    {
        $client = $this->client();

        $crawler = $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-coverage-empty]'));
        self::assertCount(0, $crawler->filter('.coverage-grid'));
    }

    public function testPreviousMonthsOpenAndOthersRedirect(): void
    {
        $client = $this->client();

        foreach (['2026-09', '2026-08'] as $month) {
            $client->request('GET', self::URL.'?month='.$month);
            self::assertResponseIsSuccessful($month);
        }
        foreach (['2026-07', '2026-11', '2026-10-01', 'oct'] as $month) {
            $client->request('GET', self::URL.'?month='.$month);
            self::assertResponseRedirects(self::URL, null, $month);
        }
        $crawler = $client->followRedirect();
        self::assertStringContainsString('только за текущий и два прошлых месяца', $crawler->filter('#admin-toast-stack')->text());
    }

    public function testNonAdminIsForbidden(): void
    {
        $client = $this->client(['ROLE_USER']);

        $client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}
