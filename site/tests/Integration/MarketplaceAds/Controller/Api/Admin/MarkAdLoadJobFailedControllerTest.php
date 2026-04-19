<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller\Api\Admin;

use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class MarkAdLoadJobFailedControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-f00000000001';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-f00000000002';
    private const OWNER_ID = '22222222-2222-2222-2222-f00000000001';
    private const OTHER_OWNER_ID = '22222222-2222-2222-2222-f00000000002';

    public function testMarksPendingJobAsFailed(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('mark-failed-happy@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->asRunning()
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($job);
        $em->flush();

        $this->loginAs($client, $owner, self::COMPANY_ID);

        $client->request(
            'POST',
            '/api/marketplace-ads/admin/load-jobs/' . $job->getId() . '/mark-failed',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Requested-With' => 'XMLHttpRequest'],
            json_encode(['reason' => 'Зависло, добиваем вручную']),
        );

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame($job->getId(), $data['jobId']);
        self::assertSame('failed', $data['status']);

        $em->clear();
        $reloaded = $em->find(AdLoadJob::class, $job->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdLoadJobStatus::FAILED, $reloaded->getStatus());
        self::assertSame('Зависло, добиваем вручную', $reloaded->getFailureReason());
        self::assertNotNull($reloaded->getFinishedAt());
    }

    public function testReturns400WhenReasonIsEmpty(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('mark-failed-empty@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->asRunning()
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($job);
        $em->flush();

        $this->loginAs($client, $owner, self::COMPANY_ID);

        $client->request(
            'POST',
            '/api/marketplace-ads/admin/load-jobs/' . $job->getId() . '/mark-failed',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Requested-With' => 'XMLHttpRequest'],
            json_encode(['reason' => '']),
        );

        self::assertResponseStatusCodeSame(400);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('reason required', $data['error']);
    }

    public function testReturns400WhenReasonIsWhitespaceOnly(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('mark-failed-spaces@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->asRunning()
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($job);
        $em->flush();

        $this->loginAs($client, $owner, self::COMPANY_ID);

        $client->request(
            'POST',
            '/api/marketplace-ads/admin/load-jobs/' . $job->getId() . '/mark-failed',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Requested-With' => 'XMLHttpRequest'],
            json_encode(['reason' => "   \t\n  "]),
        );

        self::assertResponseStatusCodeSame(400);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('reason required', $data['error']);
    }

    public function testReturns404WhenJobBelongsToAnotherCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('mark-failed-idor-own@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $otherOwner = UserBuilder::aUser()
            ->withId(self::OTHER_OWNER_ID)
            ->withEmail('mark-failed-idor-other@example.test')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()
            ->withId(self::OTHER_COMPANY_ID)
            ->withOwner($otherOwner)
            ->build();

        $otherJob = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::OTHER_COMPANY_ID)
            ->withIndex(1)
            ->asRunning()
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($otherOwner);
        $em->persist($otherCompany);
        $em->persist($otherJob);
        $em->flush();

        $this->loginAs($client, $owner, self::COMPANY_ID);

        $client->request(
            'POST',
            '/api/marketplace-ads/admin/load-jobs/' . $otherJob->getId() . '/mark-failed',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Requested-With' => 'XMLHttpRequest'],
            json_encode(['reason' => 'атака']),
        );

        self::assertResponseStatusCodeSame(404);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Job not found or already finalized', $data['error']);

        $em->clear();
        $reloaded = $em->find(AdLoadJob::class, $otherJob->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdLoadJobStatus::RUNNING, $reloaded->getStatus());
        self::assertNull($reloaded->getFailureReason());
    }

    public function testReturns404WhenJobIsAlreadyCompleted(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('mark-failed-completed@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->asCompleted()
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($job);
        $em->flush();

        $this->loginAs($client, $owner, self::COMPANY_ID);

        $client->request(
            'POST',
            '/api/marketplace-ads/admin/load-jobs/' . $job->getId() . '/mark-failed',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Requested-With' => 'XMLHttpRequest'],
            json_encode(['reason' => 'поздно']),
        );

        self::assertResponseStatusCodeSame(404);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Job not found or already finalized', $data['error']);

        $em->clear();
        $reloaded = $em->find(AdLoadJob::class, $job->getId());
        self::assertSame(AdLoadJobStatus::COMPLETED, $reloaded->getStatus());
    }

    public function testReturns403WhenUserIsNotCompanyOwner(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('mark-failed-403-owner@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $regular = UserBuilder::aUser()
            ->withId(self::OTHER_OWNER_ID)
            ->withEmail('mark-failed-403-user@example.test')
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->asRunning()
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($regular);
        $em->persist($job);
        $em->flush();

        $this->loginAs($client, $regular, self::COMPANY_ID);

        $client->request(
            'POST',
            '/api/marketplace-ads/admin/load-jobs/' . $job->getId() . '/mark-failed',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Requested-With' => 'XMLHttpRequest'],
            json_encode(['reason' => 'попытка']),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testReturns404WhenJobIdIsNotUuid(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('mark-failed-notuuid@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $this->loginAs($client, $owner, self::COMPANY_ID);

        $client->request(
            'POST',
            '/api/marketplace-ads/admin/load-jobs/not-a-uuid/mark-failed',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Requested-With' => 'XMLHttpRequest'],
            json_encode(['reason' => 'x']),
        );

        self::assertResponseStatusCodeSame(404);
    }

    private function loginAs($client, $user, string $companyId): void
    {
        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $companyId);
        $session->save();
    }
}
