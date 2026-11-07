<?php

declare(strict_types=1);

namespace App\Tests\Functional\Loan;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Loan\Entity\Loan;
use App\Loan\Entity\LoanPaymentSchedule;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Smoke-тест страницы графика платежей: рендер и кнопка удаления через диалог подтверждения. */
final class LoanSchedulePageTest extends WebTestCaseBase
{
    public function testSchedulePageRendersItemsWithConfirmTriggers(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->company('loan-schedule-owner@example.test');
        $loan = new Loan($company, 'Кредит на оборудование', '100000.00', new \DateTimeImmutable('2026-01-10'));
        $item = new LoanPaymentSchedule($loan, new \DateTimeImmutable('2026-02-10'), '10500.00', '10000.00', '500.00', '0.00');
        $this->em()->persist($loan);
        $this->em()->persist($item);
        $this->em()->flush();
        $this->em()->clear();

        $this->login($client, $owner, $company);
        $crawler = $client->request('GET', sprintf('/loans/%s/schedule', $loan->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', '10.02.2026');
        self::assertCount(1, $crawler->filter('#loan-schedule-confirm[data-confirm-modal]'));
        $trigger = $crawler->filter(sprintf('[data-confirm-action="/loans/%s/schedule/%s/delete"]', $loan->getId(), $item->getId()));
        self::assertCount(1, $trigger);
        self::assertSame('danger', $trigger->attr('data-confirm-variant'));
        self::assertNotSame('', (string) $trigger->attr('data-confirm-token'));
    }

    public function testLoanOfAnotherCompanyIsNotFound(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->company('loan-schedule-owner-a@example.test');
        [, $otherCompany] = $this->company('loan-schedule-owner-b@example.test');
        $foreign = new Loan($otherCompany, 'Чужой займ', '1.00', new \DateTimeImmutable('2026-01-10'));
        $this->em()->persist($foreign);
        $this->em()->flush();

        $this->login($client, $owner, $company);
        $client->request('GET', sprintf('/loans/%s/schedule', $foreign->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{User, Company} */
    private function company(string $email): array
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail($email)->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();

        return [$owner, $company];
    }

    private function login(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
    }
}
