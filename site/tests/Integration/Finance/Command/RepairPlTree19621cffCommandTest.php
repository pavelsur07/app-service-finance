<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Command;

use App\Company\Entity\Company;
use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Command\RepairPlTree19621cffCommand as Repair;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RepairPlTree19621cffCommandTest extends IntegrationTestCase
{
    /**
     * Дерево ОПиУ компании на проде 29.10.2026: [id, родитель, код, название, тип, направление, формула, порядок].
     */
    private const array TREE = [
        ['ba3b4793-6622-4cc6-8bd3-392e6036e78c', null, 'REV_TOTAL', 'Выручка', 'KPI', 'NONE', 'REV_NOT_SPP + REV_RETURNS', 10],
        ['d634670f-c54a-4db7-9bea-eff2cd06af9e', null, 'COGS_TOTAL', 'Прямые переменные расходы', 'SUBTOTAL', 'EXPENSE', null, 20],
        ['c50c08dd-5218-42d2-ad20-5bfc5e4f0300', null, 'CM1', 'Маржинальная прибыль CM1', 'KPI', 'NONE', 'REV_TOTAL + COGS_TOTAL', 25],
        ['01a188d0-7c61-70dd-8e33-119ea30592fd', null, 'CM1K', 'Маржинальность CM1, %', 'KPI', 'NONE', 'CM1 / REV_NOT_SPP_TOTAL', 27],
        ['c5095a1c-2cc6-46f0-8f35-de1f755b0c96', null, 'PROMO_TOTAL', 'Расходы на продвижение', 'SUBTOTAL', 'EXPENSE', null, 30],
        ['a3793f33-3fa9-44c6-b484-80b8b386c829', null, 'CM2', 'Маржинальная прибыль 2 (CM2)', 'KPI', 'NONE', 'CM1 + PROMO_TOTAL', 35],
        ['bd946fef-0b58-4ecd-bad3-ccf0a4be81ab', null, 'GROSS_MARGI_CM1', 'Маржинальность CM1, %', 'KPI', 'NONE', 'REV_TOTAL', 35],
        ['e4fe409d-a711-4af5-93a2-8657b01586dc', null, 'CM2K', 'Маржинальность CM2%', 'KPI', 'NONE', 'CM2 / REV_NOT_SPP_TOTAL', 36],
        ['01a188d0-7c65-7121-8a07-47701bac732f', null, 'DRR', 'Доля рекламных расходов (ДРР)%', 'KPI', 'NONE', 'PROMO_TOTAL / REV_NOT_SPP_TOTAL', 37],
        ['01a188d0-7c66-722a-8c6c-834fc30c3a4e', null, 'OPEX_WH_TOTAL', 'Операционные расходы склад / прочие расходы МП', 'SUBTOTAL', 'EXPENSE', null, 40],
        ['01a188d0-7c69-72ca-8309-c57e29f7a5cf', null, 'CM3', 'Маржинальная прибыль 3 (CM3)', 'KPI', 'NONE', 'CM2 + OPEX_WH_TOTAL', 45],
        ['01a188d0-7c69-72ca-8309-c57e2a4eef34', null, 'CM3K', 'Маржинальность CM3%', 'KPI', 'NONE', 'CM3 / REV_NOT_SPP_TOTAL', 47],
        ['01a188d0-7c6a-71cf-bb00-4752a819d204', null, 'PRODUCT_INFRA_TOTAL', 'Расходы товарной инфраструктуры', 'SUBTOTAL', 'NONE', null, 47],
        ['01a188d0-7c6c-7142-9eea-cbc0c7787e62', null, 'CM4', 'Операционная прибыль после товарной инфраструктуры', 'KPI', 'NONE', 'CM3 + PRODUCT_INFRA_TOTAL', 48],
        ['01a188d0-7c6c-7142-9eea-cbc0c79994d9', null, 'CM4K', 'Маржинальность опер. прибыль после тов. инфраструктуры %', 'KPI', 'NONE', 'CM4 / REV_NOT_SPP_TOTAL', 49],
        ['01a188d0-7c6d-723f-926c-aae5f946315c', null, 'OVERHEAD_TOTAL', 'Косвенные расходы', 'SUBTOTAL', 'EXPENSE', null, 50],
        ['27d378b7-6129-4a5f-b9c2-061676bdff3e', null, 'OPEX_TOTAL', 'Операционные расходы (OPEX)', 'SUBTOTAL', 'NONE', null, 50],
        ['ad9f2762-d6cb-4a6a-895f-35c285fd2eae', null, 'EBITDA', 'EBITDA (операционная прибыль)', 'KPI', 'NONE', 'CM4 + OVERHEAD_TOTAL', 60],
        ['01a188d0-7c7d-728d-9461-cb436c6450bc', null, 'EBITDAK', 'Операционная рентабельность (EBITDA)%', 'KPI', 'NONE', 'EBITDA / REV_NOT_SPP_TOTAL', 62],
        ['01a188d0-7c7e-70f5-9b69-8eb07a744caf', null, 'BELOW_EBITDA_TOTAL', 'Расходы ниже EBITDA', 'SUBTOTAL', 'EXPENSE', null, 65],
        ['01a188d0-7c82-7299-ad93-61ba4beb9d38', null, 'BELOW_EBITDA_INCOME_TOTAL', 'Доходы ниже EBITDA', 'SUBTOTAL', 'NONE', null, 66],
        ['01a188d0-7c83-717b-95ac-993bbb5ebd5b', null, 'NET_PROFIT', 'Чистая прибыль', 'KPI', 'NONE', 'EBITDA+BELOW_EBITDA_TOTAL', 70],
        ['d28c5b7a-b14e-4bc4-ba21-00311c15fe0d', null, 'EBITDA_MARGIN', 'Рентабельность по EBITDA', 'KPI', 'NONE', 'EBITDA / REV_TOTAL', 70],
        ['01a188d0-7c84-713b-8a08-8f35312a8dbd', null, 'PROFIT_DISTR_TOTAL', 'Распределение прибыли', 'SUBTOTAL', 'NONE', null, 80],
        ['94c0e0b0-1d67-491f-be08-4af9c417ab70', null, 'BELOW_EBITDA_EXPENSES', 'Расходы ниже EBITDA', 'SUBTOTAL', 'EXPENSE', null, 80],
        ['e043c8e8-fc48-4bde-b6b1-5db482b32b32', null, 'BELOW_EBITDA_INCOME', 'Доходы ниже EBITDA', 'SUBTOTAL', 'NONE', null, 90],
        ['e46de814-956f-4ef6-8a5f-bd38f88e4be3', null, null, 'Дивиденды', 'LEAF_INPUT', 'EXPENSE', null, 100],
        ['01a188d0-7c59-716f-9554-22e5bce438aa', 'ba3b4793-6622-4cc6-8bd3-392e6036e78c', 'REV_NOT_SPP_TOTAL', 'Выручка без СПП', 'SUBTOTAL', 'INCOME', null, 5],
        ['01a188d0-7c7f-7154-b146-41cef40b488d', '01a188d0-7c7e-70f5-9b69-8eb07a744caf', 'BELOW_EBITDA_DEPRECIATION', 'Амортизация', 'LEAF_INPUT', 'EXPENSE', null, 5],
        ['01a188d0-7c66-722a-8c6c-834fc37ea298', '01a188d0-7c66-722a-8c6c-834fc30c3a4e', 'OPEX_WH_STORAGE', 'Хранение', 'LEAF_INPUT', 'EXPENSE', null, 10],
        ['01a188d0-7c6a-71cf-bb00-4752a8b74f85', '01a188d0-7c6a-71cf-bb00-4752a819d204', 'PRODUCT_INFRA_LOGISTICS_TO_FF', 'Логистика до ФФ', 'LEAF_INPUT', 'EXPENSE', null, 10],
        ['01a188d0-7c6d-723f-926c-aae5fa3ddbcc', '01a188d0-7c6d-723f-926c-aae5f946315c', 'OVERHEAD_PROD_TOTAL', 'Производственные', 'SUBTOTAL', 'EXPENSE', null, 10],
        ['01a188d0-7c80-7013-87c5-22c3a466f23f', '01a188d0-7c7e-70f5-9b69-8eb07a744caf', 'BELOW_EBITDA_VAT', 'НДС начисленный 5% / 7% / 22%', 'LEAF_INPUT', 'EXPENSE', null, 10],
        ['01a188d0-7c84-713b-8a08-8f353209d3da', '01a188d0-7c84-713b-8a08-8f35312a8dbd', 'PROFIT_DISTR_DIVIDENDS', 'Дивиденды', 'LEAF_INPUT', 'EXPENSE', null, 10],
        ['4f6d60e1-0352-47e1-8da5-f329fc82f4e0', 'e043c8e8-fc48-4bde-b6b1-5db482b32b32', 'INTEREST_INCOME', 'Проценты по депозитам', 'LEAF_INPUT', 'NONE', null, 10],
        ['55d59732-4f8f-4c0b-a49f-0f641f97e32c', 'd634670f-c54a-4db7-9bea-eff2cd06af9e', 'COGS_PRODUCT', 'Себестоимость', 'SUBTOTAL', 'EXPENSE', null, 10],
        ['f334662e-edf1-4a83-948c-579ae97dac84', 'c5095a1c-2cc6-46f0-8f35-de1f755b0c96', 'PROMO_INTERNAL', 'Внутренняя реклама', 'LEAF_INPUT', 'EXPENSE', null, 10],
        ['fb65adfc-681d-4399-ac3c-272d869d729f', '94c0e0b0-1d67-491f-be08-4af9c417ab70', 'INTEREST_EXPENSES', 'Проценты по кредитам и займам', 'LEAF_INPUT', 'EXPENSE', null, 10],
        ['2ab90e88-a6cd-404b-956f-349ad60ddf73', 'd634670f-c54a-4db7-9bea-eff2cd06af9e', 'MP_OTHER_DEDUCTIONS', 'Прочие удержания МП (штрафы, и т.д.)', 'LEAF_INPUT', 'EXPENSE', null, 16],
        ['01a188d0-7c66-722a-8c6c-834fc40a9d7a', '01a188d0-7c66-722a-8c6c-834fc30c3a4e', 'OPEX_WH_RECEIVING', 'Приёмка', 'LEAF_INPUT', 'EXPENSE', null, 20],
        ['01a188d0-7c6b-7015-a742-6e9755553926', '01a188d0-7c6a-71cf-bb00-4752a819d204', 'PRODUCT_INFRA_FF_SERVICES', 'Услуги ФФ', 'LEAF_INPUT', 'EXPENSE', null, 20],
        ['01a188d0-7c71-7373-a790-ed7f54277918', '01a188d0-7c6d-723f-926c-aae5f946315c', 'OVERHEAD_ADMIN_TOTAL', 'Административные', 'SUBTOTAL', 'EXPENSE', null, 20],
        ['01a188d0-7c80-7013-87c5-22c3a4ff1845', '01a188d0-7c7e-70f5-9b69-8eb07a744caf', 'BELOW_EBITDA_USN', 'УСН / АУСН  (Д−Р)', 'LEAF_INPUT', 'EXPENSE', null, 20],
        ['0b7ce745-5fd4-442d-9a04-12a4cdc7ba81', 'd634670f-c54a-4db7-9bea-eff2cd06af9e', 'COGS_MP_COMMISSION', 'Комиссия МП', 'LEAF_INPUT', 'EXPENSE', null, 20],
        ['5e9fa727-59d1-4ca9-b02f-89ea4cf836ba', 'c5095a1c-2cc6-46f0-8f35-de1f755b0c96', 'PROMO_EXTERNAL', 'Внешняя реклама', 'LEAF_INPUT', 'EXPENSE', null, 20],
        ['8889e4eb-94c8-4296-9235-e26a3885fae5', 'd634670f-c54a-4db7-9bea-eff2cd06af9e', 'MP_STORAGE', 'Хранение на складе МП (приёмка, хранение)', 'LEAF_INPUT', 'EXPENSE', null, 20],
        ['c3d686bf-6da4-4fd9-9ed1-eaecff44c769', '94c0e0b0-1d67-491f-be08-4af9c417ab70', 'INCOME_TAX', 'Налог на прибыль (доходы)', 'LEAF_INPUT', 'NONE', null, 20],
        ['c9bf9a27-12b2-44a4-88e4-3c98876c6b85', 'ba3b4793-6622-4cc6-8bd3-392e6036e78c', 'REV_WITH_SPP', 'Выручка с СПП', 'SUBTOTAL', 'INCOME', null, 20],
        ['01a188d0-7c81-70d1-b1dc-6627249bc0c2', '01a188d0-7c7e-70f5-9b69-8eb07a744caf', 'BELOW_EBITDA_PROFIT_TAX', 'Налог на прибыль', 'LEAF_INPUT', 'EXPENSE', null, 25],
        ['01a188d0-7c67-7190-a524-3d46b81385cf', '01a188d0-7c66-722a-8c6c-834fc30c3a4e', 'OPEX_WH_PENALTIES', 'Штрафы', 'LEAF_INPUT', 'EXPENSE', null, 30],
        ['01a188d0-7c6b-7015-a742-6e9755eb65cc', '01a188d0-7c6a-71cf-bb00-4752a819d204', 'PRODUCT_INFRA_LOGISTICS_FF_TO_MP', 'Логистика от ФФ до МП', 'LEAF_INPUT', 'EXPENSE', null, 30],
        ['01a188d0-7c79-718a-b282-e0251bebdd75', '01a188d0-7c6d-723f-926c-aae5f946315c', 'OVERHEAD_SALES_TOTAL', 'Коммерческие', 'SUBTOTAL', 'EXPENSE', null, 30],
        ['01a188d0-7c81-70d1-b1dc-6627253b0072', '01a188d0-7c7e-70f5-9b69-8eb07a744caf', 'BELOW_EBITDA_INTEREST', 'Проценты по кредитам', 'LEAF_INPUT', 'EXPENSE', null, 30],
        ['a1ae0b65-baa6-4f6c-80ab-3b922029cd5c', 'd634670f-c54a-4db7-9bea-eff2cd06af9e', 'COGS_DELIVERY', 'Логистика до клиента', 'LEAF_INPUT', 'EXPENSE', null, 30],
        ['c672e299-4914-4d92-9986-62743cd3e75f', 'c5095a1c-2cc6-46f0-8f35-de1f755b0c96', 'PROMO_GIVEAWAYS', 'Раздачи / самовыкупы / отзывы', 'LEAF_INPUT', 'EXPENSE', null, 30],
        ['01a188d0-7c67-7190-a524-3d46b88b11b1', '01a188d0-7c66-722a-8c6c-834fc30c3a4e', 'OPEX_WH_MP_DEDUCTIONS', 'Прочие удержания МП', 'LEAF_INPUT', 'EXPENSE', null, 40],
        ['762b6a79-4879-4d6b-a24a-4ea778eded3d', 'd634670f-c54a-4db7-9bea-eff2cd06af9e', 'COGS_RETURNS_DELIVERY', 'Логистика возвратов', 'LEAF_INPUT', 'EXPENSE', null, 40],
        ['01a188d0-7c68-71c4-98c2-b1f4f74d98b3', '01a188d0-7c66-722a-8c6c-834fc30c3a4e', 'OPEX_WH_COMPENSATION', 'Компенсации МП', 'LEAF_INPUT', 'INCOME', null, 50],
        ['64bc4c63-b80f-403d-9d6b-c9c10a95e40b', 'd634670f-c54a-4db7-9bea-eff2cd06af9e', 'COGS_ACQUIRING', 'Эквайринг', 'LEAF_INPUT', 'EXPENSE', null, 50],
        ['01a188d0-7c68-71c4-98c2-b1f4f7b6b339', '01a188d0-7c66-722a-8c6c-834fc30c3a4e', 'OPEX_WH_STRAXOVKA', 'Страхование товаров', 'LEAF_INPUT', 'EXPENSE', null, 60],
        ['51be5181-a00b-498d-ad36-836b17ce057c', '27d378b7-6129-4a5f-b9c2-061676bdff3e', 'OPEX_PROD_FIXED', 'Производственные фиксированные', 'SUBTOTAL', 'NONE', null, 60],
        ['01a188d0-7c82-7299-ad93-61ba4c204593', '01a188d0-7c82-7299-ad93-61ba4beb9d38', 'BELOW_EBITDA_OTHER_INCOME', 'Прочие доходы', 'LEAF_INPUT', 'NONE', null, 90],
        ['01a188d0-7c83-717b-95ac-993bbb4a8bf7', '01a188d0-7c82-7299-ad93-61ba4beb9d38', 'BELOW_EBITDA_DEPOSIT_INTEREST_INCOME', 'Проценты по депозитам', 'LEAF_INPUT', 'INCOME', null, 100],
        ['489c990d-b378-4550-91cd-b6a254497981', '27d378b7-6129-4a5f-b9c2-061676bdff3e', 'OPEX_GNA', 'Административные (G&A)', 'SUBTOTAL', 'EXPENSE', null, 110],
        ['5a9caae6-f7ee-4418-a2c6-80e8f32a523c', '27d378b7-6129-4a5f-b9c2-061676bdff3e', 'OPEX_MKT', 'Маркетинговые и коммерческие', 'SUBTOTAL', 'EXPENSE', null, 170],
        ['01a188d0-7c5e-72fa-9bc7-5a54f1015125', '55d59732-4f8f-4c0b-a49f-0f641f97e32c', 'COGS_PRODUCT_REV', 'Себестоимость продаж', 'LEAF_INPUT', 'EXPENSE', null, 10],
        ['01a188d0-7c6e-71ed-a0cc-3e7e089f2988', '01a188d0-7c6d-723f-926c-aae5fa3ddbcc', 'OVERHEAD_PROD_RENT', 'Аренда офис-склад', 'LEAF_INPUT', 'EXPENSE', null, 10],
        ['01a188d0-7c71-7373-a790-ed7f546a10a4', '01a188d0-7c71-7373-a790-ed7f54277918', 'OVERHEAD_ADMIN_PAYROLL', 'ЗП административного персонала', 'LEAF_INPUT', 'EXPENSE', null, 10],
        ['01a188d0-7c79-718a-b282-e0251c608c57', '01a188d0-7c79-718a-b282-e0251bebdd75', 'OVERHEAD_SALES_PAYROLL', 'ЗП коммерческого персонала', 'LEAF_INPUT', 'EXPENSE', null, 10],
        ['883e8f5a-316a-480b-875c-402110d1a890', '01a188d0-7c59-716f-9554-22e5bce438aa', 'REV_NOT_SPP', 'Продажи без СПП', 'LEAF_INPUT', 'INCOME', null, 10],
        ['f580a3c1-342b-449d-9d2c-007be9f9858f', 'c9bf9a27-12b2-44a4-88e4-3c98876c6b85', 'REV_SPP_SALES', 'Продажи с СПП', 'LEAF_INPUT', 'INCOME', null, 10],
        ['01a188d0-7c5f-72dd-99fa-aea4922d2a65', '55d59732-4f8f-4c0b-a49f-0f641f97e32c', 'COGS_PRODUCT_RET', 'Себестоимость возвратов', 'LEAF_INPUT', 'INCOME', null, 20],
        ['01a188d0-7c6f-7337-96f2-ca86321f90b8', '01a188d0-7c6d-723f-926c-aae5fa3ddbcc', 'OVERHEAD_PROD_MAINT', 'Содержание офис-склада', 'LEAF_INPUT', 'EXPENSE', null, 20],
        ['01a188d0-7c72-7332-94aa-fe394044df9e', '01a188d0-7c71-7373-a790-ed7f54277918', 'OVERHEAD_ADMIN_PAYROLL_TAX', 'Налоги на ФОТ', 'LEAF_INPUT', 'EXPENSE', null, 20],
        ['01a188d0-7c7a-72b8-9bb8-409a92a082f8', '01a188d0-7c79-718a-b282-e0251bebdd75', 'OVERHEAD_SALES_CONTENT', 'Фото/видео/дизайн контент', 'LEAF_INPUT', 'EXPENSE', null, 20],
        ['63411175-935c-4956-ba2b-38c649314e24', 'c9bf9a27-12b2-44a4-88e4-3c98876c6b85', 'REV_SPP_RETURNS', 'Возвраты с СПП (−)', 'LEAF_INPUT', 'EXPENSE', null, 20],
        ['83ad7980-8ed1-4f2e-b994-8527437c8292', '01a188d0-7c59-716f-9554-22e5bce438aa', 'REV_NOT_SPP_SALES', 'Продажи без СПП', 'LEAF_INPUT', 'INCOME', null, 25],
        ['01a188d0-7c70-7018-a085-5bb5e835613c', '01a188d0-7c6d-723f-926c-aae5fa3ddbcc', 'OVERHEAD_PROD_PAYROLL', 'ЗП производственного персонала', 'LEAF_INPUT', 'EXPENSE', null, 30],
        ['01a188d0-7c72-7332-94aa-fe3940b762ae', '01a188d0-7c71-7373-a790-ed7f54277918', 'OVERHEAD_ADMIN_COMMUNICATIONS', 'Связь, интернет', 'LEAF_INPUT', 'EXPENSE', null, 30],
        ['01a188d0-7c7a-72b8-9bb8-409a931fe785', '01a188d0-7c79-718a-b282-e0251bebdd75', 'OVERHEAD_SALES_RND', 'Разработка новинок (R&D)', 'LEAF_INPUT', 'EXPENSE', null, 30],
        ['6848b5dc-7bb6-408c-89ef-2bd6f4c8f117', '01a188d0-7c59-716f-9554-22e5bce438aa', 'REV_RETURNS', 'Возвраты без СПП (−)', 'LEAF_INPUT', 'EXPENSE', null, 30],
        ['01a188d0-7c70-7018-a085-5bb5e8744217', '01a188d0-7c6d-723f-926c-aae5fa3ddbcc', 'OVERHEAD_PROD_CERT', 'Сертификация / Честный знак', 'LEAF_INPUT', 'EXPENSE', null, 40],
        ['01a188d0-7c72-7332-94aa-fe3941b41630', '01a188d0-7c71-7373-a790-ed7f54277918', 'OVERHEAD_ADMIN_CONTRACTORS', 'Административные подрядчики', 'LEAF_INPUT', 'EXPENSE', null, 40],
        ['01a188d0-7c7b-721b-b576-1c5850040a67', '01a188d0-7c79-718a-b282-e0251bebdd75', 'OVERHEAD_SALES_SUBS', 'Сервисы и подписки (коммерческие)', 'LEAF_INPUT', 'EXPENSE', null, 40],
        ['01a188d0-7c73-73ef-8e2e-92bc52308fbc', '01a188d0-7c71-7373-a790-ed7f54277918', 'OVERHEAD_ADMIN_SUBS', 'Сервисы и подписки', 'LEAF_INPUT', 'EXPENSE', null, 50],
        ['01a188d0-7c7b-721b-b576-1c58501ec6e3', '01a188d0-7c79-718a-b282-e0251bebdd75', null, 'ЗП коммерческого персонала бонусная-часть', 'LEAF_INPUT', 'EXPENSE', null, 50],
        ['01a188d0-7c77-707b-8aad-2469c42b5039', '01a188d0-7c71-7373-a790-ed7f54277918', 'OVERHEAD_ADMIN_BANK', 'Банковские комиссии и (РКО)', 'LEAF_INPUT', 'EXPENSE', null, 60],
        ['01a188d0-7c78-706e-8a2a-7a06ecef4e16', '01a188d0-7c71-7373-a790-ed7f54277918', 'OVERHEAD_ADMIN_OTHER', 'Прочие расходы', 'LEAF_INPUT', 'EXPENSE', null, 70],
        ['8ccec9ee-fd9d-4dd6-a336-b82f892e7e2a', '51be5181-a00b-498d-ad36-836b17ce057c', 'OPEX_PROD_RENT', 'Аренда и содержание склада/офиса', 'LEAF_INPUT', 'EXPENSE', null, 70],
        ['27591271-b55b-4836-9322-477668cdd1df', '51be5181-a00b-498d-ad36-836b17ce057c', 'OPEX_PROD_WAGES', 'Зарплата производственного персонала', 'LEAF_INPUT', 'EXPENSE', null, 80],
        ['9d91e682-4ef0-48cd-9364-cd25e506bf29', '51be5181-a00b-498d-ad36-836b17ce057c', 'OPEX_PROD_COMPLIANCE', 'Сертификация, «Честный знак»', 'LEAF_INPUT', 'EXPENSE', null, 90],
        ['56c0c6aa-4113-4efd-95c2-6b456e80c6eb', '51be5181-a00b-498d-ad36-836b17ce057c', 'OPEX_PROD_SOFTWARE', 'Онлайн-сервисы для производства', 'LEAF_INPUT', 'EXPENSE', null, 100],
        ['25438586-1123-4e3b-840e-e10a8ddcb976', '51be5181-a00b-498d-ad36-836b17ce057c', 'OPEX_LOG_PROD_MP', 'Логистика производство / маркетплейс', 'LEAF_INPUT', 'EXPENSE', null, 110],
        ['333bc95e-b2e4-44ef-bfdc-bfb43a2fa919', '51be5181-a00b-498d-ad36-836b17ce057c', null, 'Прочие производственные', 'LEAF_INPUT', 'EXPENSE', null, 120],
        ['9ad793db-56a5-489a-96a0-d4b3e1aec29c', '489c990d-b378-4550-91cd-b6a254497981', 'OPEX_GNA_WAGES', 'Зарплата HQ (админ + бухгалтерия + финдиректор)', 'LEAF_INPUT', 'EXPENSE', null, 120],
        ['c5970599-2383-4645-831d-28215a40b013', '489c990d-b378-4550-91cd-b6a254497981', 'OPEX_GNA_PAYROLL_TAXES', 'Налоги на ФОТ', 'LEAF_INPUT', 'EXPENSE', null, 130],
        ['45110a99-ae89-4d6f-ac14-528922696e05', '489c990d-b378-4550-91cd-b6a254497981', 'OPEX_GNA_IT', 'IT-сервисы административные', 'LEAF_INPUT', 'EXPENSE', null, 140],
        ['58b84ade-1b51-4b34-b761-7d6b91965ba4', '489c990d-b378-4550-91cd-b6a254497981', 'OPEX_GNA_CONTRACTORS', 'Подрядчики (юристы, консалтинг, бухучёт)', 'LEAF_INPUT', 'EXPENSE', null, 150],
        ['4638aa79-8b46-498e-af87-d663f3c52af0', '489c990d-b378-4550-91cd-b6a254497981', 'OPEX_GNA_OTHER', 'Прочие административные', 'LEAF_INPUT', 'EXPENSE', null, 160],
        ['de0f8082-db68-4b1e-8785-a9a4f0291b6b', '5a9caae6-f7ee-4418-a2c6-80e8f32a523c', 'OPEX_MKT_WAGES', 'Зарплата маркетологов / SMM', 'LEAF_INPUT', 'NONE', null, 180],
        ['7b997da3-3ab2-498c-8dcd-fdc234a8ea92', '5a9caae6-f7ee-4418-a2c6-80e8f32a523c', 'OPEX_MKT_CONTENT', 'Создание контента (фото, видео, блогеры, UGC)', 'LEAF_INPUT', 'EXPENSE', null, 190],
        ['47c40397-a7f3-4740-9598-71280c203970', '5a9caae6-f7ee-4418-a2c6-80e8f32a523c', 'OPEX_MKT_SUBSCRIPTIONS', 'Подписки на онлайн-сервисы маркетинга', 'LEAF_INPUT', 'EXPENSE', null, 200],
        ['1089f779-9701-4335-a917-1e6891e08315', '5a9caae6-f7ee-4418-a2c6-80e8f32a523c', 'OPEX_MKT_RND', 'Разработка новинок (R&D, сэмплы, тесты)', 'LEAF_INPUT', 'EXPENSE', null, 210],
    ];

    private const string PROJECT = '0b8f6d36-6a5e-4b8a-9d6e-3f1c2a7b9e01';
    private const string MANUAL_DOC = 'f729ec98-e161-4130-a26a-91717cb2e352';

    private Company $company;
    private string $currentMonthDoc;

    public function testPreviewRollsBackExecuteRepairsAndRerunFinishesDeletes(): void
    {
        $this->seed();
        $netBefore = $this->netByDay();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('Предпросмотр', $tester->getDisplay());
        self::assertSame(Repair::COGS_PRODUCT, $this->saleTarget('07dbf485-1cb6-4de7-a897-c86903fb882b'), 'предпросмотр откатывается');
        self::assertSame('REV_NOT_SPP_SALES', $this->code(Repair::REV_NOT_SPP_SALES));

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--execute' => true]), $tester->getDisplay());
        self::assertStringContainsString('Отложено удалений: 2', $tester->getDisplay());

        // Правила, кредиты, ДДС.
        self::assertSame(Repair::COGS_PRODUCT_REV, $this->saleTarget('07dbf485-1cb6-4de7-a897-c86903fb882b'));
        self::assertSame(Repair::COGS_PRODUCT_RET, $this->saleTarget('01a158ef-ebf2-7399-bea8-9674549e6274'));
        foreach (Repair::COST_RULES as $id => [, , , $to]) {
            self::assertSame($to, $this->one('SELECT pl_category_id FROM marketplace_cost_pl_mappings WHERE id = :id', $id));
        }
        foreach (array_keys(Repair::LOANS) as $id) {
            self::assertSame(Repair::BELOW_EBITDA_INTEREST, $this->one('SELECT pl_category_interest_id FROM finance_loan WHERE id = :id', $id));
        }
        self::assertSame(Repair::PROFIT_DISTR_DIVIDENDS, $this->one('SELECT pl_category_id FROM cashflow_categories WHERE id = :id', Repair::CASHFLOW_DIVIDENDS));

        // Операции: себестоимость по знаку, затраты МП по категории, ручной документ по ID.
        $expected = [
            'BELOW_EBITDA_INTEREST' => '47285.47',
            'COGS_PRODUCT_RET' => '-28088.79',
            'COGS_PRODUCT_REV' => '394657.00',
            'MP_OTHER_DEDUCTIONS' => '-50.00',
            'MP_STORAGE' => '-100.00',
            'OPEX_GNA_OTHER' => '-17004.79',
            'OPEX_WH_MP_DEDUCTIONS' => '24123.69',
            'OPEX_WH_STORAGE' => '8434.13',
            'PROFIT_DISTR_DIVIDENDS' => '6000.00',
            'REV_NOT_SPP' => '1889186.48',
        ];
        $actual = $this->sumsByCode();
        ksort($actual);
        self::assertSame($expected, $actual);
        self::assertSame('389505.43', $this->one("SELECT sum(o.amount)::text FROM document_operations o JOIN documents d ON d.id = o.document_id WHERE o.category_id = :id AND d.type = 'marketplace_pl'", Repair::COGS_PRODUCT_REV));

        // Регистр: старых статей нет, итог каждого дня прежний.
        self::assertSame(0, (int) $this->one('SELECT count(*) FROM pl_daily_totals WHERE pl_category_id = :id', Repair::COGS_PRODUCT));
        self::assertSame('389505.43', $this->one("SELECT expense::text FROM (SELECT sum(amount_expense) AS expense FROM pl_daily_totals WHERE pl_category_id = :id AND date = '2026-09-30') t", Repair::COGS_PRODUCT_REV));
        self::assertSame('28088.79', $this->one("SELECT income::text FROM (SELECT sum(amount_income) AS income FROM pl_daily_totals WHERE pl_category_id = :id AND date = '2026-09-30') t", Repair::COGS_PRODUCT_RET));
        self::assertSame($netBefore, $this->netByDay());

        // Структура.
        self::assertSame('REV_NOT_SPP', $this->code(Repair::REV_NOT_SPP_SALES));
        self::assertSame(Repair::NET_PROFIT_FORMULA, $this->one('SELECT formula FROM pl_categories WHERE id = :id', Repair::NET_PROFIT));
        self::assertSame(Repair::OVERHEAD_TOTAL, $this->one('SELECT parent_id FROM pl_categories WHERE id = :id', Repair::OPEX_MKT));
        self::assertSame('OPEX_MKT_WAGES_BONUS', $this->code(Repair::SALES_BONUS));
        self::assertSame('OPEX_PROD_OTHER', $this->code(Repair::OPEX_PROD_OTHER));
        self::assertSame('EXPENSE', $this->one('SELECT flow FROM pl_categories WHERE id = :id', Repair::OPEX_MKT_WAGES));
        self::assertSame(75, $this->categoriesCount(), '103 − 28 удалённых; MP_STORAGE и MP_OTHER_DEDUCTIONS отложены');
        self::assertSame(0, (int) $this->one("SELECT count(*) FROM pl_categories WHERE company_id = :id AND type = 'LEAF_INPUT' AND code IS NULL", Repair::COMPANY_ID));

        // Ночное оперативное закрытие пересобирает текущий месяц по новым правилам.
        $this->connection->executeStatement('UPDATE document_operations SET category_id = :to WHERE document_id = :doc', ['to' => Repair::WH_STORAGE, 'doc' => $this->currentMonthDoc]);
        $this->recalc((new \DateTimeImmutable('today'))->format('Y-m-d'));

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--execute' => true]), $tester->getDisplay());
        self::assertStringContainsString('Записано изменений: 2.', $tester->getDisplay());
        self::assertSame(73, $this->categoriesCount());

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--execute' => true]), $tester->getDisplay());
        self::assertStringContainsString('Записано изменений: 0.', $tester->getDisplay());
    }

    public function testRuleChangedSinceReconciliationAbortsEverything(): void
    {
        $this->seed();
        $this->connection->executeStatement(
            'UPDATE marketplace_cost_pl_mappings SET pl_category_id = :to WHERE id = :id',
            ['to' => Repair::WH_STORAGE, 'id' => '615c7655-1ce6-4c90-b3b7-f6c4053d0f40'],
        );

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute(['--execute' => true]));
        self::assertStringContainsString('Ошибок: 1', $tester->getDisplay());
        self::assertSame(Repair::COGS_PRODUCT, $this->saleTarget('07dbf485-1cb6-4de7-a897-c86903fb882b'), 'откат целиком');
        self::assertSame(103, $this->categoriesCount());
    }

    public function testDayTotalChangeAbortsEverything(): void
    {
        $this->seed();
        // Сторно без начисления в своей новой статье: модуль за день перевернул бы его в расход.
        $doc = $this->document('2026-08-31', 'marketplace_pl', 'marketplace_ozon');
        $this->operation($doc, Repair::MP_OTHER_DEDUCTIONS, '-1000.00', '[Оперативное закрытие 31.08.2026 04:45] Категория ozon_early_payment');
        $this->operation($doc, Repair::MP_OTHER_DEDUCTIONS, '400.00', '[Оперативное закрытие 31.08.2026 04:45] Категория ozon_service_correction [сторно]');
        $this->recalc('2026-08-31');

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute(['--execute' => true]));
        self::assertStringContainsString('итог дня -600.00 → -1400.00', $tester->getDisplay());
        self::assertSame(103, $this->categoriesCount());
    }

    public function testUnknownMarketplaceCostOperationAbortsEverything(): void
    {
        $this->seed();
        $doc = $this->document('2026-08-31', 'marketplace_pl', 'marketplace_ozon');
        $this->operation($doc, Repair::MP_STORAGE, '-10.00', '[Оперативное закрытие 31.08.2026 04:45] Неизвестная услуга');

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute(['--execute' => true]));
        self::assertStringContainsString('не знаю, куда перенести', $tester->getDisplay());
    }

    private function seed(): void
    {
        $owner = UserBuilder::aUser()->withIndex(962)->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(962)->withId(Repair::COMPANY_ID)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();

        $c = Repair::COMPANY_ID;
        $now = '2026-10-29 12:00:00';
        $this->connection->insert('project_directions', ['id' => self::PROJECT, 'company_id' => $c, 'name' => 'Основной']);

        /** @var array<string, int> $levels */
        $levels = [];
        foreach (self::TREE as [$id, $parent, $code, $name, $type, $flow, $formula, $sort]) {
            $levels[$id] = null === $parent ? 1 : $levels[$parent] + 1;
            $this->connection->insert('pl_categories', [
                'id' => $id, 'company_id' => $c, 'parent_id' => $parent, 'name' => $name, 'level' => $levels[$id],
                'sort_order' => $sort, 'code' => $code, 'type' => $type, 'flow' => $flow, 'formula' => $formula,
            ]);
        }

        foreach (Repair::SALE_RULES as $id => [$label]) {
            [$marketplace, $source] = explode(' ', $label);
            $this->connection->insert('marketplace_sale_mappings', [
                'id' => $id, 'company_id' => $c, 'pl_category_id' => Repair::COGS_PRODUCT, 'marketplace' => $marketplace,
                'operation_type' => str_starts_with($source, 'sale') ? 'sale' : 'return', 'amount_source' => $source,
                'is_negative' => 'true', 'is_active' => 'true', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (Repair::COST_RULES as $id => [$marketplace, $code, $from]) {
            $costCategory = Uuid::uuid4()->toString();
            $this->connection->insert('marketplace_cost_categories', [
                'id' => $costCategory, 'company_id' => $c, 'marketplace' => $marketplace, 'code' => $code, 'name' => 'Категория '.$code,
                'is_active' => 'true', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->connection->insert('marketplace_cost_pl_mappings', [
                'id' => $id, 'company_id' => $c, 'cost_category_id' => $costCategory, 'pl_category_id' => $from,
                'include_in_pl' => 'true', 'is_negative' => 'true', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (Repair::LOANS as $id => $name) {
            $this->connection->insert('finance_loan', [
                'id' => $id, 'company_id' => $c, 'name' => $name, 'principal_amount' => '1000000.00', 'remaining_principal' => '1000000.00',
                'start_date' => '2025-01-01', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                'pl_category_interest_id' => Repair::INTEREST_EXPENSES,
            ]);
        }

        $this->connection->insert('cashflow_categories', [
            'id' => Repair::CASHFLOW_DIVIDENDS, 'company_id' => $c, 'name' => 'Дивиденды', 'status' => 'ACTIVE', 'sort' => 0,
            'allow_pl_document' => 'true', 'pl_category_id' => Repair::DIVIDENDS_ROOT,
        ]);

        // Ручной документ WB 11.2025 — операции по ID.
        $this->document('2025-11-30', 'DEAL_SALE', null, self::MANUAL_DOC);
        $this->operation(self::MANUAL_DOC, Repair::COGS_PRODUCT, '394657.00', null);
        $this->operation(self::MANUAL_DOC, Repair::OLD_REV_NOT_SPP, '1889186.48', null);
        $this->operation(self::MANUAL_DOC, Repair::MP_OTHER_DEDUCTIONS, '24423.69', null, 'ce7a583a-1a23-4ffe-bf4a-c2b06a4dff32');
        $this->operation(self::MANUAL_DOC, Repair::MP_STORAGE, '11590.77', null, 'ed8a6947-3c34-47cb-b484-a187faac3b30');

        // Оперативное закрытие Ozon за сентябрь: себестоимость по знаку, затраты по категории.
        $revenue = $this->document('2026-09-30', 'marketplace_pl', 'marketplace_ozon');
        $this->operation($revenue, Repair::COGS_PRODUCT, '389505.43', '[Оперативное закрытие 30.09.2026 04:45] Продажи и возвраты');
        $this->operation($revenue, Repair::COGS_PRODUCT, '-28088.79', '[Оперативное закрытие 30.09.2026 04:45] Продажи и возвраты');
        $costs = $this->document('2026-09-30', 'marketplace_pl', 'marketplace_ozon');
        $this->operation($costs, Repair::MP_STORAGE, '-3156.64', '[Оперативное закрытие 30.09.2026 04:45] Категория ozon_storage');
        $this->operation($costs, Repair::MP_OTHER_DEDUCTIONS, '-17004.79', '[Оперативное закрытие 30.09.2026 04:45] Категория ozon_early_payment');
        $this->operation($costs, Repair::MP_OTHER_DEDUCTIONS, '-800.00', '[Оперативное закрытие 30.09.2026 04:45] Категория ozon_disposal');
        $this->operation($costs, Repair::MP_OTHER_DEDUCTIONS, '500.00', '[Оперативное закрытие 30.09.2026 04:45] Категория ozon_service_correction [сторно]');

        $this->operation($this->document('2025-11-18', 'LOANS', null), Repair::INTEREST_EXPENSES, '47285.47', null);
        $this->operation($this->document('2026-03-23', 'CASHFLOW_EXPENSE', null), Repair::DIVIDENDS_ROOT, '6000.00', null);

        // Текущий месяц не переносится: его пересоберёт ночное закрытие.
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->currentMonthDoc = $this->document($today, 'marketplace_pl', 'marketplace_ozon');
        $this->operation($this->currentMonthDoc, Repair::MP_STORAGE, '-100.00', '[Оперативное закрытие] Категория ozon_storage');
        $this->operation($this->currentMonthDoc, Repair::MP_OTHER_DEDUCTIONS, '-50.00', '[Оперативное закрытие] Категория ozon_disposal');

        foreach (['2025-11-18', '2025-11-30', '2026-03-23', '2026-09-30', $today] as $day) {
            $this->recalc($day);
        }
    }

    private function document(string $date, string $type, ?string $source, ?string $id = null): string
    {
        $id ??= Uuid::uuid4()->toString();
        $this->connection->insert('documents', [
            'id' => $id, 'company_id' => Repair::COMPANY_ID, 'date' => $date.' 00:00:00', 'type' => $type, 'status' => 'ACTIVE',
            'project_direction_id' => self::PROJECT, 'source' => $source,
        ]);

        return $id;
    }

    private function operation(string $document, string $category, string $amount, ?string $comment, ?string $id = null): void
    {
        $this->connection->insert('document_operations', [
            'id' => $id ?? Uuid::uuid4()->toString(), 'document_id' => $document, 'category_id' => $category, 'amount' => $amount, 'comment' => $comment,
        ]);
    }

    private function recalc(string $day): void
    {
        $this->em->clear();
        $company = $this->em->find(Company::class, Repair::COMPANY_ID);
        self::assertNotNull($company);
        $date = new \DateTimeImmutable($day);
        self::getContainer()->get(PLRegisterUpdater::class)->recalcRange($company, $date, $date);
    }

    /**
     * @return array<string, string>
     */
    private function netByDay(): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            "SELECT to_char(date, 'YYYY-MM-DD'), sum(amount_income - amount_expense)::text FROM pl_daily_totals WHERE company_id = :id GROUP BY 1 ORDER BY 1",
            ['id' => Repair::COMPANY_ID],
        );

        return array_map('strval', $rows);
    }

    /**
     * Сумма операций по коду статьи; себестоимость продаж из документов маркетплейса проверяется отдельно.
     *
     * @return array<string, string>
     */
    private function sumsByCode(): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            "SELECT c.code, sum(o.amount)::text FROM document_operations o JOIN documents d ON d.id = o.document_id JOIN pl_categories c ON c.id = o.category_id
             WHERE d.company_id = :id AND NOT (c.code = 'COGS_PRODUCT_REV' AND d.type = 'marketplace_pl') GROUP BY c.code",
            ['id' => Repair::COMPANY_ID],
        );

        return array_map('strval', $rows);
    }

    private function saleTarget(string $id): string
    {
        return $this->one('SELECT pl_category_id FROM marketplace_sale_mappings WHERE id = :id', $id);
    }

    private function code(string $id): ?string
    {
        $value = $this->connection->fetchOne('SELECT code FROM pl_categories WHERE id = :id', ['id' => $id]);

        return false === $value || null === $value ? null : (string) $value;
    }

    private function categoriesCount(): int
    {
        return (int) $this->one('SELECT count(*) FROM pl_categories WHERE company_id = :id', Repair::COMPANY_ID);
    }

    private function one(string $sql, string $id): string
    {
        return (string) $this->connection->fetchOne($sql, ['id' => $id]);
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $this->em->clear();

        return new CommandTester((new Application(self::$kernel))->find('app:finance:repair-pl-tree-19621cff'));
    }
}
