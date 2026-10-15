<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company\Facade;

use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;
use App\Company\Enum\CounterpartyType;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\CounterpartyFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Методы фасадов Company, через которые Cash перестал читать репозитории Company
 * (этап 7.5 плана Cash): пользователь, проект по умолчанию, контрагенты импорта.
 */
final class CompanyFacadeCashLookupsTest extends IntegrationTestCase
{
    private CompanyFacade $companyFacade;
    private CounterpartyFacade $counterpartyFacade;
    private CounterpartyNameNormalizer $normalizer;
    private Company $company;
    private Company $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyFacade = self::getContainer()->get(CompanyFacade::class);
        $this->counterpartyFacade = self::getContainer()->get(CounterpartyFacade::class);
        $this->normalizer = new CounterpartyNameNormalizer();
        $this->company = $this->company('lookups@example.com');
        $this->foreign = $this->company('lookups-foreign@example.com');
        $this->em->flush();
    }

    public function testUserExists(): void
    {
        self::assertTrue($this->companyFacade->userExists((string) $this->company->getUser()?->getId()));
        self::assertFalse($this->companyFacade->userExists(Uuid::uuid4()->toString()));
        self::assertFalse($this->companyFacade->userExists('not-a-uuid'));
    }

    public function testDefaultProjectDirectionIsCompanyScoped(): void
    {
        $general = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'Основной', ProjectDirection::CODE_GENERAL);
        $this->em->persist($general);
        $this->em->flush();

        self::assertSame($general, $this->companyFacade->findDefaultProjectDirection((string) $this->company->getId()));
        self::assertNull($this->companyFacade->findDefaultProjectDirection((string) $this->foreign->getId()));
        self::assertNull($this->companyFacade->findDefaultProjectDirection(Uuid::uuid4()->toString()));
    }

    public function testListProjectDirectionEntitiesInTreeOrNameOrder(): void
    {
        $root = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'Б-корень');
        $child = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'В-дочерний');
        $child->setParent($root);
        $other = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'А-другой');
        $this->em->persist($root);
        $this->em->persist($child);
        $this->em->persist($other);
        $this->em->persist(new ProjectDirection(Uuid::uuid4()->toString(), $this->foreign, 'Чужой'));
        $this->em->flush();
        $id = (string) $this->company->getId();
        $names = static fn (array $projects): array => array_map(static fn (ProjectDirection $p): string => $p->getName(), $projects);

        self::assertSame(['А-другой', 'Б-корень', 'В-дочерний'], $names($this->companyFacade->listProjectDirectionEntities($id, treeOrder: false)));
        $tree = $names($this->companyFacade->listProjectDirectionEntities($id, treeOrder: true));
        self::assertLessThan(array_search('В-дочерний', $tree, true), array_search('Б-корень', $tree, true));
        self::assertNotContains('Чужой', $tree);
        self::assertSame([], $this->companyFacade->listProjectDirectionEntities(Uuid::uuid4()->toString(), treeOrder: true));
    }

    public function testFindOrCreateByInnReusesCompanyCounterpartyAndCreatesOtherwise(): void
    {
        $existing = $this->counterparty($this->company, 'ООО "Ромашка"', '7707083893');
        $this->counterparty($this->foreign, 'ООО "Чужая"', '7701000001');
        $this->em->flush();
        $id = (string) $this->company->getId();

        self::assertSame(['7707083893'], $this->counterpartyFacade->findExistingInns($id, ['7707083893', '7701000001', '0000000000']));
        self::assertSame([], $this->counterpartyFacade->findExistingInns($id, []));

        $found = $this->counterpartyFacade->findOrCreateByInn($this->company, '7707083893', $this->normalizer->normalize('Другое имя'), CounterpartyType::LEGAL_ENTITY);
        self::assertSame($existing, $found);

        $created = $this->counterpartyFacade->findOrCreateByInn($this->company, '7701000001', $this->normalizer->normalize('ООО "Новая"'), CounterpartyType::LEGAL_ENTITY);
        self::assertNotSame($existing, $created);
        self::assertSame('7701000001', $created->getInn());
        self::assertTrue($this->em->contains($created));
        $this->em->flush();
        self::assertCount(2, $this->em->getRepository(Counterparty::class)->findBy(['company' => $this->company]));
    }

    public function testFindOrCreateByNormalizedNameMatchesRegardlessOfQuotesAndForm(): void
    {
        $existing = $this->counterparty($this->company, 'ООО "Ромашка"', null);
        $this->em->flush();

        $found = $this->counterpartyFacade->findOrCreateByNormalizedName($this->company, $this->normalizer->normalize('"Ромашка" ООО'), CounterpartyType::LEGAL_ENTITY);
        self::assertSame($existing, $found);

        $created = $this->counterpartyFacade->findOrCreateByNormalizedName($this->company, $this->normalizer->normalize('АО "Ромашка"'), CounterpartyType::LEGAL_ENTITY);
        self::assertNotSame($existing, $created);
        self::assertTrue($this->em->contains($created));
    }

    private function company(string $email): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail($email)->withPasswordHash('p')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function counterparty(Company $company, string $name, ?string $inn): Counterparty
    {
        $counterparty = new Counterparty(Uuid::uuid4()->toString(), $company, $this->normalizer->normalize($name), CounterpartyType::LEGAL_ENTITY);
        $counterparty->assignTaxIds($inn, null);
        $this->em->persist($counterparty);

        return $counterparty;
    }
}
