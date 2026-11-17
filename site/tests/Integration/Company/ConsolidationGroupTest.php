<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Application\ConfigureConsolidationGroupAction;
use App\Company\Entity\User;
use App\Company\Exception\InvalidConsolidationGroupException;
use App\Company\Facade\ConsolidationGroupFacade;
use App\Company\Repository\ConsolidationMembershipRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\ConsolidationMembershipBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Группа консолидации: головная компания и состав (задача cashflow-group-chart, этап 1b). */
final class ConsolidationGroupTest extends IntegrationTestCase
{
    private const HEAD = '0193dddd-0000-7000-8000-0000000000a1';
    private const A = '0193dddd-0000-7000-8000-0000000000a2';
    private const B = '0193dddd-0000-7000-8000-0000000000a3';
    private const C = '0193dddd-0000-7000-8000-0000000000a4';
    private const FOREIGN = '0193dddd-0000-7000-8000-0000000000b1';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = UserBuilder::aUser()->withIndex(1)->build();
        $stranger = UserBuilder::aUser()->withIndex(2)->build();
        $this->em->persist($this->owner);
        $this->em->persist($stranger);
        foreach ([self::HEAD, self::A, self::B, self::C] as $i => $id) {
            $this->em->persist(CompanyBuilder::aCompany()->withId($id)->withName('Компания '.$i)->withOwner($this->owner)->build());
        }
        $this->em->persist(CompanyBuilder::aCompany()->withId(self::FOREIGN)->withName('Чужая')->withOwner($stranger)->build());
        $this->em->flush();
    }

    public function testEnableReplaceAndDisable(): void
    {
        $this->configure(self::HEAD, true, [self::A, self::B]);
        self::assertSame([self::HEAD => self::HEAD, self::A => self::HEAD, self::B => self::HEAD], $this->rows());

        $this->configure(self::HEAD, true, [self::B, self::C]);
        self::assertSame([self::HEAD => self::HEAD, self::B => self::HEAD, self::C => self::HEAD], $this->rows());

        $this->configure(self::HEAD, true, [self::B, self::C]);
        self::assertCount(3, $this->rows(), 'повтор того же состава ничего не меняет');

        $this->configure(self::HEAD, false, []);
        self::assertSame([], $this->rows());
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function refusals(): iterable
    {
        yield 'компания чужого владельца' => [[self::FOREIGN], 'не принадлежит'];
        yield 'головная компания в составе' => [[self::HEAD], 'сама головная'];
    }

    /** @param list<string> $members */
    #[DataProvider('refusals')]
    public function testRefusals(array $members, string $message): void
    {
        $this->expectRefusal(fn () => $this->configure(self::HEAD, true, $members), $message);
        self::assertSame([], $this->rows());
    }

    public function testMemberOfAnotherGroupIsRefused(): void
    {
        $this->configure(self::A, true, [self::B]);

        $this->expectRefusal(fn () => $this->configure(self::HEAD, true, [self::B]), 'уже входит в группу');
        $this->expectRefusal(fn () => $this->configure(self::HEAD, true, [self::A]), 'уже входит в группу');
        self::assertSame([self::A => self::A, self::B => self::A], $this->rows());
    }

    public function testMemberCannotBecomeHead(): void
    {
        $this->configure(self::HEAD, true, [self::A]);

        $this->expectRefusal(fn () => $this->configure(self::A, true, [self::B]), 'уже входит в группу');
        $this->expectRefusal(fn () => $this->configure(self::A, false, []), 'уже входит в группу');
        self::assertSame([self::HEAD => self::HEAD, self::A => self::HEAD], $this->rows());
    }

    public function testOnlyOwnerOfHeadConfigures(): void
    {
        $this->expectRefusal(fn () => $this->configure(self::FOREIGN, true, []), 'не принадлежит');
        self::assertSame([], $this->rows());
    }

    public function testFacade(): void
    {
        $this->em->persist(ConsolidationMembershipBuilder::aMembership()->forCompany(self::HEAD)->withHead(self::HEAD)->build());
        $this->em->persist(ConsolidationMembershipBuilder::aMembership()->forCompany(self::B)->withHead(self::HEAD)->build());
        $this->em->persist(ConsolidationMembershipBuilder::aMembership()->forCompany(self::A)->withHead(self::HEAD)->build());
        $this->em->flush();
        // Потребители Facade появятся на этапе 2; до тех пор контейнер его вырезает.
        $facade = new ConsolidationGroupFacade(self::getContainer()->get(ConsolidationMembershipRepository::class));

        self::assertSame(self::HEAD, $facade->headOf(self::HEAD));
        self::assertSame(self::HEAD, $facade->headOf(self::A));
        self::assertNull($facade->headOf(self::C));
        self::assertSame([self::HEAD, self::A, self::B], $facade->members(self::HEAD));
        self::assertSame([], $facade->members(self::A), 'участник — не головная');
        self::assertSame([], $facade->members(self::C));
    }

    /** @param list<string> $members */
    private function configure(string $head, bool $enabled, array $members): void
    {
        self::getContainer()->get(ConfigureConsolidationGroupAction::class)($head, (string) $this->owner->getId(), $enabled, $members);
    }

    private function expectRefusal(callable $call, string $message): void
    {
        try {
            $call();
            self::fail('Ожидался отказ: '.$message);
        } catch (InvalidConsolidationGroupException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    /** @return array<string, string> company_id => head_company_id */
    private function rows(): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->em->getConnection()->fetchAllKeyValue('SELECT company_id, head_company_id FROM company_consolidation_member ORDER BY company_id');

        return $rows;
    }
}
