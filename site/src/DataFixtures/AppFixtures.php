<?php

namespace App\DataFixtures;

use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Entity\User;
use App\Enum\CounterpartyType;
use App\Enum\MoneyAccountType;
use App\Balance\Service\BalanceStructureSeeder;
use App\Cash\Service\Accounts\AccountBalanceService;
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
    public const REF_ACC_SBER = 'money_account.sber'; // ➕ новый счёт

    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private AccountBalanceService $accountBalanceService,
        private BalanceStructureSeeder $balanceStructureSeeder,
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

        $this->balanceStructureSeeder->seedDefaultIfEmpty($company);

        // --- Контрагенты для ООО "Ромашка" (добавлены Wildberries и Ozon + сервисные контрагенты)
        $counterpartiesData = [
            // Маркетплейсы как юрлица
            ['Wildberries', '7700000000', CounterpartyType::LEGAL_ENTITY],
            ['Ozon',        '7800000000', CounterpartyType::LEGAL_ENTITY],

            // Юрлица (ИНН 10 знаков)
            ['ООО "Альфа-Снаб"',     '7708123456',   CounterpartyType::LEGAL_ENTITY],
            ['ООО "Бета-Логистик"',  '7812345678',   CounterpartyType::LEGAL_ENTITY],
            ['ООО "Гамма-Маркет"',   '7723456789',   CounterpartyType::LEGAL_ENTITY],
            ['ООО "Дельта-Сервис"',  '7712345670',   CounterpartyType::LEGAL_ENTITY],

            // Индивидуальные предприниматели (ИНН 12 знаков)
            ['ИП Иванов И.И.',       '503212345678', CounterpartyType::INDIVIDUAL_ENTREPRENEUR],
            ['ИП Петров П.П.',       '540812345678', CounterpartyType::INDIVIDUAL_ENTREPRENEUR],

            // Доп. контрагенты для эмуляции ДДС
            ['АО «Альфа-Банк» (Эквайринг/Кредит)', '7700000001',  CounterpartyType::LEGAL_ENTITY],
            ['ООО «Опт-Трейд»',                    '7700000002',  CounterpartyType::LEGAL_ENTITY],
            ['ООО «Digital Ads»',                  '7700000003',  CounterpartyType::LEGAL_ENTITY],
            ['Сотрудники (ведомость)',             '7700000004',  CounterpartyType::LEGAL_ENTITY],
            ['ООО «Офис-Парк»',                    '7700000005',  CounterpartyType::LEGAL_ENTITY],
            ['АО «ГорКомСервис»',                  '7700000006',  CounterpartyType::LEGAL_ENTITY],
            ['ООО «Цифра-Сервисы»',                '7700000007',  CounterpartyType::LEGAL_ENTITY],
            ['ООО «ГородКурьер»',                  '7700000008',  CounterpartyType::LEGAL_ENTITY],
            ['ФНС России',                          '7700000009',  CounterpartyType::LEGAL_ENTITY],
            ['Учредитель Иванов И.И.',             '503200000010', CounterpartyType::INDIVIDUAL_ENTREPRENEUR],
            ['ООО «Производ-Техника»',             '7700000011',  CounterpartyType::LEGAL_ENTITY],
            ['ООО «Офис-Мебель»',                  '7700000012',  CounterpartyType::LEGAL_ENTITY],
            ['ООО «1С-Франчайзи РомСофт»',         '7700000013',  CounterpartyType::LEGAL_ENTITY],
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

        // === Дата начальных остатков ===
        // ВАЖНО: Используем первый день ПРОШЛОГО месяца (00:00:00)
        $openingDate = (new \DateTimeImmutable('first day of last month'))->setTime(0, 0, 0);

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
        $alfa->setOpeningBalanceDate($openingDate);
        $manager->persist($alfa);
        $this->addReference(self::REF_ACC_ALFA, $alfa);

        // Сбербанк (расчётный) — ➕ новый счёт для техпереводов «Альфа → Сбер» и прочей эмуляции
        $sber = new MoneyAccount(
            id: Uuid::uuid4()->toString(),
            company: $company,
            type: MoneyAccountType::BANK,
            name: 'Сбербанк (расчётный)',
            currency: 'RUB'
        );
        $sber->setAccountNumber('40702810999999999999');
        $sber->setOpeningBalance('0.00');
        $sber->setOpeningBalanceDate($openingDate);
        $manager->persist($sber);
        $this->addReference(self::REF_ACC_SBER, $sber);

        // Касса (основная)
        $cash = new MoneyAccount(
            id: Uuid::uuid4()->toString(),
            company: $company,
            type: MoneyAccountType::CASH,
            name: 'Касса (основная)',
            currency: 'RUB'
        );
        $cash->setOpeningBalance('100.00');
        $cash->setOpeningBalanceDate($openingDate);
        $manager->persist($cash);
        $this->addReference(self::REF_ACC_CASH, $cash);

        $manager->flush();

        // ➜ Пересчёт дневных остатков: с даты начальных остатков по сегодня
        $today = new \DateTimeImmutable('today');
        $this->accountBalanceService->recalculateDailyRange($company, $alfa, $openingDate, $today);
        $this->accountBalanceService->recalculateDailyRange($company, $sber, $openingDate, $today); // ➕ учли Сбер
        $this->accountBalanceService->recalculateDailyRange($company, $cash, $openingDate, $today);

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
