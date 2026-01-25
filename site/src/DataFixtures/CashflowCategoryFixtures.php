<?php

namespace App\DataFixtures;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Company\Entity\Company;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Nonstandard\Uuid;

final class CashflowCategoryFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        /** @var Company $company */
        $company = $this->getReference(AppFixtures::REF_COMPANY_ROMASHKA, Company::class);

        // Хелпер создания категории
        $make = function (string $name, ?CashflowCategory $parent, int $sort, ?string $desc = null) use ($company, $manager): CashflowCategory {
            $cat = new CashflowCategory(
                id: Uuid::uuid4()->toString(),
                company: $company
            );
            $cat->setName($name);
            if (null !== $desc) {
                $cat->setDescription($desc);
            }
            if (null !== $parent) {
                $cat->setParent($parent);
            }
            $cat->setSort($sort);

            $manager->persist($cat);

            return $cat;
        };

        $sort = 0;

        // ===== 1) Операционная деятельность
        $op = $make('Операционная деятельность', null, ++$sort, 'Основной денежный поток: закупки, производство, продажи');

        // 1.1 Доходы от продаж
        $op_income = $make('Доходы от продаж', $op, 10);
        $make('Продажи Wildberries', $op_income, 11);
        $make('Продажи Ozon', $op_income, 12);
        $make('Продажи интернет-магазин', $op_income, 13, 'D2C (собственный сайт)');
        $make('Оптовые продажи', $op_income, 14);

        // 1.2 Прямые расходы (Себестоимость)
        $op_cogs = $make('Прямые расходы (Себестоимость)', $op, 20);
        $make('Закупка сырья и материалов', $op_cogs, 21);
        $make('Оплата подрядчиков производства', $op_cogs, 22);
        $make('Логистика до склада (входящая)', $op_cogs, 23);
        $make('Упаковка и этикетки', $op_cogs, 24);

        // 1.3 Маркетинг и продажи
        $op_mkt = $make('Маркетинг и продажи', $op, 30);
        $make('Комиссии маркетплейсов', $op_mkt, 31);
        $make('Реклама (таргет, блогеры, контекст)', $op_mkt, 32);
        $make('Фото/видеосъёмка контента', $op_mkt, 33);

        // 1.4 Операционные расходы
        $op_opex = $make('Операционные расходы', $op, 40);
        $make('Зарплата сотрудников', $op_opex, 41);
        $make('Аренда офиса/склада', $op_opex, 42);
        $make('Коммунальные услуги', $op_opex, 43);
        $make('IT-сервисы и подписки', $op_opex, 44);
        $make('Транспортные расходы', $op_opex, 45);

        // 1.5 Налоги операционные
        $op_tax = $make('Налоги операционные', $op, 50);
        $make('НДС (уплата/возврат)', $op_tax, 51);
        $make('Налог на прибыль / УСН', $op_tax, 52);
        $make('Страховые взносы', $op_tax, 53);

        // ===== 2) Финансовая деятельность
        $fin = $make('Финансовая деятельность', null, ++$sort, 'Долги, проценты, дивиденды, капитал');

        // 2.1 Привлечение и возврат заемных средств
        $fin_debt = $make('Привлечение и возврат заемных средств', $fin, 10);
        $make('Поступления по кредитам и займам', $fin_debt, 11);
        $make('Погашение кредитов и займов', $fin_debt, 12);
        $make('Проценты по кредитам и займам', $fin_debt, 13);

        // 2.2 Выплаты собственникам
        $fin_owner = $make('Выплаты собственникам', $fin, 20);
        $make('Дивиденды', $fin_owner, 21);
        $make('Выплаты по займу учредителю', $fin_owner, 22);

        // 2.3 Привлечение капитала
        $fin_cap = $make('Привлечение капитала', $fin, 30);
        $make('Взносы в уставный капитал', $fin_cap, 31);
        $make('Инвестиции от учредителей/инвесторов', $fin_cap, 32);

        // ===== 3) Инвестиционная деятельность
        $inv = $make('Инвестиционная деятельность', null, ++$sort, 'Долгосрочные вложения и выбытия');

        // 3.1 Приобретение внеоборотных активов
        $inv_buy = $make('Приобретение внеоборотных активов', $inv, 10);
        $make('Оборудование для производства', $inv_buy, 11);
        $make('Мебель и техника (офис/склад)', $inv_buy, 12);
        $make('ПО (единовременные лицензии)', $inv_buy, 13);

        // 3.2 Продажа внеоборотных активов
        $inv_sell = $make('Продажа внеоборотных активов', $inv, 20);
        $make('Продажа оборудования', $inv_sell, 21);
        $make('Возврат инвестиций', $inv_sell, 22);

        // ===== 4) Технические операции
        $tech = $make('Технические операции', null, ++$sort, 'Перемещения между счетами и валютные операции');

        // 4.1 Перемещения между счетами
        $tech_move = $make('Перемещения между счетами', $tech, 10);
        $make('Перевод с расчётного счёта в кассу', $tech_move, 11);
        $make('Перевод между банками', $tech_move, 12);
        $make('Пополнение корпоративных карт', $tech_move, 13);

        // 4.2 Валютные операции
        $tech_fx = $make('Валютные операции', $tech, 20);
        $make('Покупка валюты', $tech_fx, 21);
        $make('Продажа валюты', $tech_fx, 22);
        $make('Курсовые разницы', $tech_fx, 23);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class, ProjectDirectionsFixtures::class];
    }
}
