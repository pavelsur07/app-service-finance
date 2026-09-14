<?php

declare(strict_types=1);

namespace App\Tests\Functional\Balance\Controller;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Enum\BalanceCategoryType;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Balance\BalanceCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BalanceStructureControllerTest extends WebTestCaseBase
{
    public function testCreatePersistsSubmittedCategory(): void
    {
        $client = static::createClient();
        $company = $this->loginOwner($client);
        $companyId = $company->getId();
        self::assertNotNull($companyId);
        $parent = BalanceCategoryBuilder::aBalanceCategory()->withCompanyId($companyId)->build();
        $this->em()->persist($parent);
        $this->em()->flush();

        $crawler = $client->request('GET', '/balance/structure/new');
        self::assertResponseIsSuccessful();
        $client->submitForm('Сохранить', [
            'balance_category_form[name]' => 'Расчётный счёт',
            'balance_category_form[type]' => $crawler->filterXPath('//select[@name="balance_category_form[type]"]/option[normalize-space(.)="Капитал"]')->attr('value'),
            'balance_category_form[parentId]' => $parent->getId(),
            'balance_category_form[code]' => 'BANK',
            'balance_category_form[isVisible]' => true,
        ]);

        self::assertResponseRedirects('/balance/structure/');
        $category = $this->em()->getRepository(BalanceCategory::class)->findOneBy([
            'companyId' => $company->getId(), 'code' => 'BANK',
        ]);
        self::assertInstanceOf(BalanceCategory::class, $category);
        self::assertSame('Расчётный счёт', $category->getName());
        self::assertSame(BalanceCategoryType::EQUITY, $category->getType());
        self::assertSame($parent->getId(), $category->getParent()?->getId());
        self::assertTrue($category->isVisible());
    }

    public function testEditPersistsChangesAndClearsOptionalFields(): void
    {
        $client = static::createClient();
        $company = $this->loginOwner($client);
        $companyId = $company->getId();
        self::assertNotNull($companyId);
        $parent = BalanceCategoryBuilder::aBalanceCategory()->withCompanyId($companyId)->build();
        $category = BalanceCategoryBuilder::aBalanceCategory()->withIndex(2)
            ->withCompanyId($companyId)->withParent($parent)->withCode('OLD')->build();
        $this->em()->persist($parent);
        $this->em()->persist($category);
        $this->em()->flush();

        $crawler = $client->request('GET', '/balance/structure/'.$category->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('balance_category_form[code]', 'OLD');
        $client->submitForm('Сохранить', [
            'balance_category_form[name]' => 'Обязательства',
            'balance_category_form[type]' => $crawler->filterXPath('//select[@name="balance_category_form[type]"]/option[normalize-space(.)="Обязательство"]')->attr('value'),
            'balance_category_form[parentId]' => '',
            'balance_category_form[code]' => '',
            'balance_category_form[isVisible]' => false,
        ]);

        self::assertResponseRedirects('/balance/structure/');
        $updated = $this->em()->getRepository(BalanceCategory::class)->findOneBy([
            'companyId' => $company->getId(), 'id' => $category->getId(),
        ]);
        self::assertInstanceOf(BalanceCategory::class, $updated);
        self::assertSame('Обязательства', $updated->getName());
        self::assertSame(BalanceCategoryType::LIABILITY, $updated->getType());
        self::assertNull($updated->getParent());
        self::assertNull($updated->getCode());
        self::assertFalse($updated->isVisible());
    }

    #[DataProvider('invalidRequiredFields')]
    public function testCreateRejectsMissingRequiredFields(string $name, ?string $type): void
    {
        $client = static::createClient();
        $company = $this->loginOwner($client);
        $client->request('GET', '/balance/structure/new');
        $form = $client->getCrawler()->selectButton('Сохранить')->form();
        $values = $form->getPhpValues();
        $values['balance_category_form']['name'] = $name;
        if (null !== $type) {
            $values['balance_category_form']['type'] = $type;
        }
        $client->request('POST', '/balance/structure/new', $values);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.invalid-feedback');
        self::assertSame(0, $this->em()->getRepository(BalanceCategory::class)->count([
            'companyId' => $company->getId(),
        ]));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function invalidRequiredFields(): iterable
    {
        yield 'blank name' => ['', null];
        yield 'missing type' => ['Активы', ''];
    }

    #[DataProvider('formModes')]
    public function testDuplicateCodeRendersErrorWithoutSaving(bool $edit): void
    {
        $client = static::createClient();
        $company = $this->loginOwner($client);
        $companyId = $company->getId();
        self::assertNotNull($companyId);
        $existing = BalanceCategoryBuilder::aBalanceCategory()->withCompanyId($companyId)->withCode('TAKEN')->build();
        $target = BalanceCategoryBuilder::aBalanceCategory()->withIndex(2)->withCompanyId($companyId)
            ->withName('Исходное название')->withCode('ORIGINAL')->build();
        $this->em()->persist($existing);
        $this->em()->persist($target);
        $this->em()->flush();

        $url = $edit ? '/balance/structure/'.$target->getId().'/edit' : '/balance/structure/new';
        $client->request('GET', $url);
        $client->submitForm('Сохранить', [
            'balance_category_form[name]' => 'Новое название',
            'balance_category_form[code]' => 'TAKEN',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('form[name="balance_category_form"]', 'Код должен быть уникален в рамках компании.');
        self::assertInputValueSame('balance_category_form[name]', 'Новое название');
        self::assertSame(2, $this->em()->getRepository(BalanceCategory::class)->count(['companyId' => $companyId]));
        $unchanged = $this->em()->getRepository(BalanceCategory::class)->findOneBy([
            'companyId' => $companyId, 'id' => $target->getId(),
        ]);
        self::assertInstanceOf(BalanceCategory::class, $unchanged);
        self::assertSame('Исходное название', $unchanged->getName());
        self::assertSame('ORIGINAL', $unchanged->getCode());
    }

    /** @return iterable<string, array{bool}> */
    public static function formModes(): iterable
    {
        yield 'create' => [false];
        yield 'edit' => [true];
    }

    #[DataProvider('formModes')]
    public function testExcessiveDepthRendersErrorWithoutSaving(bool $edit): void
    {
        $client = static::createClient();
        $company = $this->loginOwner($client);
        $companyId = $company->getId();
        self::assertNotNull($companyId);
        $parent = null;
        for ($level = 1; $level <= 5; ++$level) {
            $parent = BalanceCategoryBuilder::aBalanceCategory()->withIndex($level)
                ->withCompanyId($companyId)->withParent($parent)->build();
            $this->em()->persist($parent);
        }
        $target = BalanceCategoryBuilder::aBalanceCategory()->withIndex(6)->withCompanyId($companyId)
            ->withName('Исходное название')->build();
        $this->em()->persist($target);
        $this->em()->flush();

        $client->request('GET', $edit ? '/balance/structure/'.$target->getId().'/edit' : '/balance/structure/new');
        $client->submitForm('Сохранить', [
            'balance_category_form[name]' => 'Новое название',
            'balance_category_form[parentId]' => $parent->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('form[name="balance_category_form"]', 'Максимальная вложенность категорий — 5 уровней.');
        self::assertInputValueSame('balance_category_form[name]', 'Новое название');
        $this->em()->clear();
        self::assertSame(6, $this->em()->getRepository(BalanceCategory::class)->count(['companyId' => $companyId]));
        $unchanged = $this->em()->getRepository(BalanceCategory::class)->findOneBy([
            'companyId' => $companyId, 'id' => $target->getId(),
        ]);
        self::assertInstanceOf(BalanceCategory::class, $unchanged);
        self::assertSame('Исходное название', $unchanged->getName());
        self::assertNull($unchanged->getParent());
    }

    private function loginOwner(KernelBrowser $client): Company
    {
        $this->resetDb();
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return $company;
    }

    public function testIndex(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->resetDb();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('balance-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('BalanceCo');

        $category = new BalanceCategory(Uuid::uuid4()->toString(), $company->getId());
        $category->setName('Активы');
        $category->setType(BalanceCategoryType::ASSET);

        $em->persist($user);
        $em->persist($company);
        $em->persist($category);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/balance/structure/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Настройка структуры баланса');
    }
}
