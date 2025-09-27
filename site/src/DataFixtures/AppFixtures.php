<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Entity\User;
use App\Enum\CounterpartyType;
use App\Enum\MoneyAccountType;
use App\Service\AccountBalanceService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Nonstandard\Uuid;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public const REF_COMPANY_ROMASHKA = 'company.romashka';
    public const REF_OWNER = 'user.owner';
    public const REF_ACC_ALFA = 'money_account.alfa';
    public const REF_ACC_CASH = 'money_account.cash';

    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private AccountBalanceService $accountBalanceService,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $usersData = [
            ['admin@app.ru',   'password', ['ROLE_ADMIN']],
            ['manager@app.ru', 'password', ['ROLE_MANAGER']],
            ['user@app.ru',    'password', ['ROLE_USER']],
        ];

        // ➜ Владелец owner@app.ru с ROLE_ADMIN
        $owner = new User(id: Uuid::uuid4()->toString());
        $owner->setEmail('owner@app.ru');
        $owner->setRoles(['ROLE_ADMIN']);
        $owner->setPassword($this->passwordHasher->hashPassword($owner, 'password'));
        $manager->persist($owner);
        $this->addReference(self::REF_OWNER, $owner);

        // ➜ Компания ООО "Ромашка"
        $company = new Company(id: Uuid::uuid4()->toString(), user: $owner);
        $company->setName('ООО "Ромашка"');
        $manager->persist($company);
        $this->addReference(self::REF_COMPANY_ROMASHKA, $company);

        // --- Контрагенты для ООО "Ромашка" (6 шт., из них 2 ИП), с ИНН
        $counterpartiesData = [
            // Юрлица (ИНН 10 знаков)
            ['ООО "Альфа-Снаб"',     '7708123456',   CounterpartyType::LEGAL_ENTITY],
            ['ООО "Бета-Логистик"',  '7812345678',   CounterpartyType::LEGAL_ENTITY],
            ['ООО "Гамма-Маркет"',   '7723456789',   CounterpartyType::LEGAL_ENTITY],
            ['ООО "Дельта-Сервис"',  '7712345670',   CounterpartyType::LEGAL_ENTITY],
            // Индивидуальные предприниматели (ИНН 12 знаков)
            ['ИП Иванов И.И.',       '503212345678', CounterpartyType::INDIVIDUAL_ENTREPRENEUR],
            ['ИП Петров П.П.',       '540812345678', CounterpartyType::INDIVIDUAL_ENTREPRENEUR],
        ];

        foreach ($counterpartiesData as [$name, $inn, $type]) {
            $cp = new Counterparty(
                id: Uuid::uuid4()->toString(),
                company: $company,
                name: $name,
                type: $type
            );
            $cp->setInn($inn);
            $manager->persist($cp);
        }

        // ➜ Денежные счета
        // Альфа-Банк (основной)
        $alfa = new MoneyAccount(
            id: Uuid::uuid4()->toString(),
            company: $company,
            type: MoneyAccountType::BANK,
            name: 'Альфа-Банк (основной)',
            currency: 'RUB'
        );
        $alfa->setAccountNumber('40702810726140001479');
        $alfa->setOpeningBalance('1000.00');
        $alfa->setOpeningBalanceDate(new \DateTimeImmutable('today'));
        $manager->persist($alfa);
        $this->addReference(self::REF_ACC_ALFA, $alfa);

        // Касса (основная)
        $cash = new MoneyAccount(
            id: Uuid::uuid4()->toString(),
            company: $company,
            type: MoneyAccountType::CASH,
            name: 'Касса (основная)',
            currency: 'RUB'
        );
        $cash->setOpeningBalance('100.00');
        $cash->setOpeningBalanceDate(new \DateTimeImmutable('today'));
        $manager->persist($cash);
        $this->addReference(self::REF_ACC_CASH, $cash);

        $manager->flush();

        // ➜ Пересчёт дневных остатков на сегодняшнюю дату
        $today = new \DateTimeImmutable('today');
        $this->accountBalanceService->recalculateDailyRange($company, $alfa, $today, $today);
        $this->accountBalanceService->recalculateDailyRange($company, $cash, $today, $today);

        // ➜ Остальные пользователи (как было)
        foreach ($usersData as [$email, $plainPassword, $roles]) {
            $user = new User(id: Uuid::uuid4()->toString());
            $user->setEmail($email);
            $user->setRoles($roles);
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $manager->persist($user);
        }

        $manager->flush();
    }
}
