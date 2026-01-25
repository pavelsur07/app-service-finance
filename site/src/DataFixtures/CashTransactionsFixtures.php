<?php

namespace App\DataFixtures;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\ProjectDirection;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Nonstandard\Uuid;

final class CashTransactionsFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        /** @var Company $company */
        $company = $this->getReference(AppFixtures::REF_COMPANY_ROMASHKA, Company::class);
        /** @var MoneyAccount $accAlfa */
        $accAlfa = $this->getReference(AppFixtures::REF_ACC_ALFA, MoneyAccount::class);
        /** @var MoneyAccount $accCash */
        $accCash = $this->getReference(AppFixtures::REF_ACC_CASH, MoneyAccount::class);

        /** @var MoneyAccount|null $accSber */
        $accSber = $this->hasReference(AppFixtures::REF_ACC_SBER, MoneyAccount::class)
            ? $this->getReference(AppFixtures::REF_ACC_SBER, MoneyAccount::class)
            : null;

        /** @var ProjectDirection $prWb */
        $prWb = $this->getReference(ProjectDirectionsFixtures::REF_PD_WB, ProjectDirection::class);
        /** @var ProjectDirection $prOzon */
        $prOzon = $this->getReference(ProjectDirectionsFixtures::REF_PD_OZON, ProjectDirection::class);
        /** @var ProjectDirection $prShop */
        $prShop = $this->getReference(ProjectDirectionsFixtures::REF_PD_SHOP, ProjectDirection::class);
        /** @var ProjectDirection $prGeneral */
        $prGeneral = $this->getReference(ProjectDirectionsFixtures::REF_PD_GENERAL, ProjectDirection::class);

        // Базовая дата: 1-е число прошлого месяца, 10:00
        $d0 = (new \DateTimeImmutable('first day of last month'))->setTime(10, 0, 0);
        $d = fn (int $plus) => $d0->modify("+{$plus} days");

        // Поиск по реальным репозиториям
        $cat = fn (string $name): ?CashflowCategory => $manager->getRepository(CashflowCategory::class)->findOneBy(['name' => $name, 'company' => $company]);
        $cp = fn (string $name): ?Counterparty => $manager->getRepository(Counterparty::class)->findOneBy(['name' => $name, 'company' => $company]);

        // Хелпер создания транзакции — точная сигнатура конструктора из проекта
        $make = function (
            \DateTimeImmutable $date,
            string $amountRub,                // положительная строка
            CashDirection $direction,         // INFLOW / OUTFLOW
            MoneyAccount $account,
            ?CashflowCategory $category,
            ?Counterparty $counterparty,
            ?ProjectDirection $project,
            string $description,
        ) use ($company, $manager): CashTransaction {
            $tx = new CashTransaction(
                Uuid::uuid4()->toString(),
                $company,
                $account,
                $direction,
                $amountRub,
                'RUB',
                $date
            );

            $tx->setDescription($description);
            if ($counterparty) {
                $tx->setCounterparty($counterparty);
            }
            if ($category) {
                $tx->setCashflowCategory($category);
            }
            if ($project) {
                $tx->setProjectDirection($project);
            }

            $manager->persist($tx);

            return $tx;
        };

        // ===== Поступления =====
        $make($d(0), '500000.00', CashDirection::INFLOW, $accAlfa, $cat('Продажи Wildberries'), $cp('Wildberries'), $prWb, 'Выручка Wildberries');
        $make($d(1), '300000.00', CashDirection::INFLOW, $accAlfa, $cat('Продажи Ozon'), $cp('Ozon'), $prOzon, 'Выручка Ozon');
        $make($d(2), '150000.00', CashDirection::INFLOW, $accAlfa, $cat('Продажи интернет-магазин'), $cp('АО «Альфа-Банк» (Эквайринг/Кредит)'), $prShop, 'Эквайринг интернет-магазина');
        $make($d(3), '200000.00', CashDirection::INFLOW, $accAlfa, $cat('Оптовые продажи'), $cp('ООО «Опт-Трейд»'), $prGeneral, 'Поступление от оптового клиента');
        $make($d(18), '1000000.00', CashDirection::INFLOW, $accAlfa, $cat('Поступления по кредитам и займам'), $cp('АО «Альфа-Банк» (Эквайринг/Кредит)'), $prGeneral, 'Поступление кредита');

        // ===== Списания (операционные) =====
        $make($d(4), '250000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Закупка сырья и материалов'), $cp('ООО "Альфа-Снаб"'), $prGeneral, 'Закупка ткани');
        $make($d(5), '100000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Закупка сырья и материалов'), $cp('ООО "Гамма-Маркет"'), $prGeneral, 'Закупка фурнитуры');
        $make($d(6), '150000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Оплата подрядчиков производства'), $cp('ИП Иванов И.И.'), $prGeneral, 'Пошив продукции');
        $make($d(7), '80000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Логистика до склада (входящая)'), $cp('ООО "Бета-Логистик"'), $prGeneral, 'Доставка до склада');
        $make($d(8), '40000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Упаковка и этикетки'), $cp('ООО "Дельта-Сервис"'), $prGeneral, 'Покупка упаковки и этикеток');

        $make($d(9), '90000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Реклама (таргет, блогеры, контекст)'), $cp('ООО «Digital Ads»'), $prGeneral, 'Реклама (таргет/блогеры)');
        $make($d(10), '30000.00', CashDirection::OUTFLOW, $accCash, $cat('Фото/видеосъёмка контента'), $cp('ИП Петров П.П.'), $prGeneral, 'Фотосъёмка (наличные)');

        $make($d(11), '180000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Зарплата сотрудников'), $cp('Сотрудники (ведомость)'), $prGeneral, 'Выплата зарплаты');
        $make($d(12), '90000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Аренда офиса/склада'), $cp('ООО «Офис-Парк»'), $prGeneral, 'Аренда');
        $make($d(13), '20000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Коммунальные услуги'), $cp('АО «ГорКомСервис»'), $prGeneral, 'Коммунальные платежи');
        $make($d(14), '15000.00', CashDirection::OUTFLOW, $accAlfa, $cat('IT-сервисы и подписки'), $cp('ООО «Цифра-Сервисы»'), $prGeneral, 'Подписки и SaaS');
        $make($d(15), '10000.00', CashDirection::OUTFLOW, $accCash, $cat('Транспортные расходы'), $cp('ООО «ГородКурьер»'), $prGeneral, 'Курьерские расходы');

        // ===== Налоги =====
        $make($d(16), '50000.00', CashDirection::OUTFLOW, $accAlfa, $cat('НДС (уплата/возврат)'), $cp('ФНС России'), $prGeneral, 'Уплата НДС');
        $make($d(17), '30000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Страховые взносы'), $cp('ФНС России'), $prGeneral, 'Страховые взносы');

        // ===== Финансовая деятельность =====
        $make($d(19), '200000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Погашение кредитов и займов'), $cp('АО «Альфа-Банк» (Эквайринг/Кредит)'), $prGeneral, 'Погашение кредита');
        $make($d(20), '25000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Проценты по кредитам и займам'), $cp('АО «Альфа-Банк» (Эквайринг/Кредит)'), $prGeneral, 'Проценты по кредиту');
        $make($d(21), '100000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Дивиденды'), $cp('Учредитель Иванов И.И.'), $prGeneral, 'Выплата дивидендов');

        // ===== Инвестиционная деятельность =====
        $make($d(22), '500000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Оборудование для производства'), $cp('ООО «Производ-Техника»'), $prGeneral, 'Покупка производственного оборудования');
        $make($d(23), '50000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Мебель и техника (офис/склад)'), $cp('ООО «Офис-Мебель»'), $prGeneral, 'Покупка офисной мебели');
        $make($d(24), '30000.00', CashDirection::OUTFLOW, $accAlfa, $cat('ПО (единовременные лицензии)'), $cp('ООО «1С-Франчайзи РомСофт»'), $prGeneral, 'Лицензия 1С');

        // ===== Технические операции (внутренние переводы) =====
        $make($d(25), '50000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Перемещения между счетами'), null, $prGeneral, 'Перевод в кассу');
        $make($d(25), '50000.00', CashDirection::INFLOW, $accCash, $cat('Перемещения между счетами'), null, $prGeneral, 'Поступление в кассу');

        if ($accSber instanceof MoneyAccount) {
            $make($d(26), '100000.00', CashDirection::OUTFLOW, $accAlfa, $cat('Перемещения между счетами'), null, $prGeneral, 'Перевод Альфа → Сбер');
            $make($d(26), '100000.00', CashDirection::INFLOW, $accSber, $cat('Перемещения между счетами'), null, $prGeneral, 'Поступление Сбер ← Альфа');
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            AppFixtures::class,
            ProjectDirectionsFixtures::class,
            CashflowCategoryFixtures::class,
        ];
    }
}
