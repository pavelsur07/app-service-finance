<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

/**
 * Двойная отправка формы планового платежа: ключ повтора выдаётся при показе формы и
 * становится ID плана, вторая отправка возвращает уже созданный.
 */
final class PaymentPlanNewIdempotencyTest extends WebTestCaseBase
{
    public function testSameFormSubmittedTwiceCreatesOnePlan(): void
    {
        $client = static::createClient();
        $suffix = substr(Uuid::uuid4()->toString(), 0, 8);
        $user = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('plan-new-'.$suffix.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($user)->withName('Plan new '.$suffix)->build();
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Аренда');
        foreach ([$user, $company, $category] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/finance/payment-calendar/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Сохранить')->form();
        $key = (string) $form->getValues()['payment_plan[idempotencyKey]'];
        self::assertTrue(Uuid::isValid($key));

        $form['payment_plan[plannedAt]'] = '2026-08-20';
        $form['payment_plan[amount]'] = '1500.00';
        $form['payment_plan[cashflowCategory]'] = (string) $category->getId();
        $values = $form->getPhpValues();

        $client->request($form->getMethod(), $form->getUri(), $values);
        self::assertResponseRedirects();
        $client->request($form->getMethod(), $form->getUri(), $values);
        self::assertResponseRedirects();

        $ids = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT id FROM payment_plan WHERE company_id = :company',
            ['company' => $company->getId()],
        );
        self::assertSame([$key], $ids);
    }
}
