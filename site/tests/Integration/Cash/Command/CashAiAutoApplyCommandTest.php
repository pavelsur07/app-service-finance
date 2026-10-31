<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Repository\Transaction\CashAiCategorizationSettingRepository;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CashAiAutoApplyCommandTest extends IntegrationTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $user = UserBuilder::aUser()->withEmail('ai-flag@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($user)->withName('Flag')->build();
        $this->em->persist($user);
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testShowsGateWithoutChangingTheFlag(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--company-id' => (string) $this->company->getId()]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('flag        off', $display);
        self::assertStringContainsString('decisions   0 of 100 needed', $display);
        self::assertStringContainsString('gate        closed: disabled', $display);
        self::assertNull(self::getContainer()->get(CashAiCategorizationSettingRepository::class)->findForCompany((string) $this->company->getId()));
    }

    public function testEnablesAndDisablesTheFlag(): void
    {
        $settings = self::getContainer()->get(CashAiCategorizationSettingRepository::class);
        $tester = $this->tester();

        $tester->execute(['--company-id' => (string) $this->company->getId(), '--enable' => true]);
        self::assertTrue($settings->isAutoApplyEnabled((string) $this->company->getId()));
        // Флаг включён, но решений людей нет — ворота закрыты.
        self::assertStringContainsString('gate        closed: too_few_decisions', $tester->getDisplay());

        $tester->execute(['--company-id' => (string) $this->company->getId(), '--disable' => true]);
        self::assertFalse($settings->isAutoApplyEnabled((string) $this->company->getId()));
    }

    public function testRejectsUnknownCompanyAndConflictingOptions(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--company-id' => Uuid::uuid4()->toString(), '--enable' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['--company-id' => (string) $this->company->getId(), '--enable' => true, '--disable' => true]));
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('app:cash:ai-auto-apply'));
    }
}
