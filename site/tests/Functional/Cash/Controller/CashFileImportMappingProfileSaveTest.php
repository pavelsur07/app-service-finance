<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Repository\Import\CashFileImportProfileRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class CashFileImportMappingProfileSaveTest extends WebTestCaseBase
{
    /**
     * Регрессия для бага из прод-профилей ИП Лазаревой «Базовый»/«Базовый - 1»:
     * пользователь корректно выбрал раздельные колонки Приход/Расход, но поле
     * «Сумма» на странице маппинга уже содержало значение (например, унаследованное
     * от ранее применённого профиля). Раньше сервер тихо отдавал приоритет amount,
     * обнуляя корректно выбранные inflow/outflow без единого предупреждения —
     * теперь раздельные колонки побеждают, а лишний amount отбрасывается.
     */
    public function testInflowOutflowWinOverLeftoverAmountValue(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()->withEmail('cash-import-mapping-bug@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $this->setClientSessionValue($client, 'cash_file_import', []);

        $token = $this->csrfToken($client, 'cash_file_import_mapping');

        $client->request('POST', '/cash/import/file/mapping/profile/save', [
            '_token' => $token,
            'profile_name' => 'Базовый',
            'date_column' => 'Дата операции',
            // Пользователь этот select не трогал — в нём осталось значение
            // от предыдущего рендера страницы (например, после "Применить профиль").
            'amount_column' => 'Дата операции',
            // Пользователь ЯВНО и корректно выбрал раздельные колонки:
            'inflow_column' => 'Приход',
            'outflow_column' => 'Расход',
            'counterparty_column' => 'Контрагент',
            'description_column' => 'Назначение платежа',
            'doc_number_column' => 'Номер документа',
        ]);

        self::assertResponseRedirects('/cash/import/file/mapping');

        /** @var CashFileImportProfileRepository $repository */
        $repository = static::getContainer()->get(CashFileImportProfileRepository::class);
        $profiles = $repository->findByCompanyAndType($company, CashFileImportProfile::TYPE_CASH_TRANSACTION);

        self::assertCount(1, $profiles);
        $mapping = $profiles[0]->getMapping();

        self::assertNull($mapping['amount'] ?? null, 'Лишний amount должен быть отброшен.');
        self::assertSame('Приход', $mapping['inflow'] ?? null, 'Корректно выбранный inflow не должен обнуляться.');
        self::assertSame('Расход', $mapping['outflow'] ?? null, 'Корректно выбранный outflow не должен обнуляться.');
    }
}
