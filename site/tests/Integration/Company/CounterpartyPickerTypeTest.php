<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Form\Type\CounterpartyPickerType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;
use Symfony\Component\Validator\Constraints\NotBlank;

final class CounterpartyPickerTypeTest extends IntegrationTestCase
{
    private FormFactoryInterface $forms;
    private Company $company;
    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var FormFactoryInterface $forms */
        $forms = self::getContainer()->get(FormFactoryInterface::class);
        $this->forms = $forms;

        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000040')->withEmail('owner-picker@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);

        $otherOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000041')->withEmail('owner-picker-2@example.com')->build();
        $this->otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $this->em->persist($otherOwner);
        $this->em->persist($this->otherCompany);
        $this->em->flush();
    }

    /**
     * Ключевое свойство виджета: без company_id форма не собирается, поэтому
     * tenant-фильтр невозможно забыть — раньше он стоял под `if ($company)`.
     */
    public function testCompanyIdIsRequired(): void
    {
        // Then
        $this->expectException(MissingOptionsException::class);

        // When
        $this->forms->create(CounterpartyPickerType::class);
    }

    public function testChoicesContainOnlyOwnCompany(): void
    {
        // Given
        $own = $this->persist($this->company, 1, 'ООО "Ромашка"', '7707083893');
        $this->persist($this->otherCompany, 2, 'ООО "Чужая"', '7707083894');
        $this->em->flush();

        // When
        $view = $this->forms->create(CounterpartyPickerType::class, null, [
            'company_id' => $this->company->getId(),
        ])->createView();

        // Then
        $values = array_map(static fn ($choice) => $choice->value, $view->vars['choices']);
        self::assertSame([$own], $values);
    }

    public function testForeignIdIsRejectedOnSubmit(): void
    {
        // Given
        $foreign = $this->persist($this->otherCompany, 2, 'ООО "Чужая"', '7707083894');
        $this->em->flush();

        $form = $this->forms->create(CounterpartyPickerType::class, null, [
            'company_id' => $this->company->getId(),
        ]);

        // When: подстановка чужого UUID в поле
        $form->submit($foreign);

        // Then
        self::assertFalse($form->isValid());
        self::assertNull($form->getData());
    }

    public function testOwnIdIsAcceptedAsString(): void
    {
        // Given
        $own = $this->persist($this->company, 1, 'ООО "Ромашка"', '7707083893');
        $this->em->flush();

        $form = $this->forms->create(CounterpartyPickerType::class, null, [
            'company_id' => $this->company->getId(),
        ]);

        // When
        $form->submit($own);

        // Then
        self::assertTrue($form->isValid());
        self::assertSame($own, $form->getData());
    }

    public function testEntityValueTypeReturnsEntity(): void
    {
        // Given
        $own = $this->persist($this->company, 1, 'ООО "Ромашка"', '7707083893');
        $this->em->flush();

        $form = $this->forms->create(CounterpartyPickerType::class, null, [
            'company_id' => $this->company->getId(),
            'value_type' => 'entity',
        ]);

        // When
        $form->submit($own);

        // Then
        self::assertTrue($form->isValid());
        self::assertInstanceOf(Counterparty::class, $form->getData());
        self::assertSame($own, $form->getData()->getId());
    }

    public function testEntityValueTypeRejectsForeignId(): void
    {
        // Given
        $foreign = $this->persist($this->otherCompany, 2, 'ООО "Чужая"', '7707083894');
        $this->em->flush();

        $form = $this->forms->create(CounterpartyPickerType::class, null, [
            'company_id' => $this->company->getId(),
            'value_type' => 'entity',
        ]);

        // When
        $form->submit($foreign);

        // Then
        self::assertFalse($form->isValid());
        self::assertNull($form->getData());
    }

    public function testEmptyValueIsAllowed(): void
    {
        // Given
        $form = $this->forms->create(CounterpartyPickerType::class, null, [
            'company_id' => $this->company->getId(),
        ]);

        // When
        $form->submit('');

        // Then
        self::assertTrue($form->isValid());
        self::assertNull($form->getData());
    }

    public function testArchivedIsNotOfferedButKeptWhenSelected(): void
    {
        // Given
        $active = $this->persist($this->company, 1, 'ООО "Активный"', null);
        $archived = $this->persistArchived($this->company, 2, 'ООО "Архивный"');
        $this->em->flush();

        // When: без keep_id архивного в списке нет
        $withoutKeep = $this->forms->create(CounterpartyPickerType::class, null, [
            'company_id' => $this->company->getId(),
        ])->createView();

        // Then
        self::assertSame(
            [$active],
            array_map(static fn ($choice) => $choice->value, $withoutKeep->vars['choices']),
        );

        // When: с keep_id он остаётся — иначе правка старой записи потеряет ссылку
        $withKeep = $this->forms->create(CounterpartyPickerType::class, null, [
            'company_id' => $this->company->getId(),
            'keep_id' => $archived,
        ])->createView();

        // Then
        self::assertContains(
            $archived,
            array_map(static fn ($choice) => $choice->value, $withKeep->vars['choices']),
        );
    }

    public function testChoiceLabelContainsInn(): void
    {
        // Given
        $this->persist($this->company, 1, 'ООО "Ромашка"', '7707083893');
        $this->em->flush();

        // When
        $view = $this->forms->create(CounterpartyPickerType::class, null, [
            'company_id' => $this->company->getId(),
        ])->createView();

        // Then: одноимённые ООО различимы только по ИНН
        self::assertStringContainsString('7707083893', $view->vars['choices'][0]->label);
    }

    /**
     * Два контрагента с одинаковой подписью: раньше choices индексировались подписью,
     * и второй затирал первого — контрагент исчезал из списка.
     */
    public function testDuplicateLabelsDoNotCollapseChoices(): void
    {
        // Given
        $first = $this->persist($this->company, 1, 'ООО "ПО Оборонхим"', null);
        $second = $this->persist($this->company, 2, 'ООО "ПО Оборонхим"', null);
        $this->em->flush();

        // When
        $view = $this->forms->create(CounterpartyPickerType::class, null, [
            'company_id' => $this->company->getId(),
        ])->createView();

        // Then
        $values = array_map(static fn ($choice) => $choice->value, $view->vars['choices']);
        self::assertContains($first, $values);
        self::assertContains($second, $values);
        self::assertCount(2, $values);
    }

    public function testDuplicateLabelValueStillSubmits(): void
    {
        // Given
        $first = $this->persist($this->company, 1, 'ООО "ПО Оборонхим"', null);
        $this->persist($this->company, 2, 'ООО "ПО Оборонхим"', null);
        $this->em->flush();

        $form = $this->forms->create(CounterpartyPickerType::class, null, [
            'company_id' => $this->company->getId(),
        ]);

        // When
        $form->submit($first);

        // Then
        self::assertTrue($form->isValid());
        self::assertSame($first, $form->getData());
    }

    /**
     * Пустая строка проходила проверку типа и молча давала пустой список вариантов:
     * форма выглядела рабочей, а выбранное значение исчезало.
     */
    public function testEmptyCompanyIdIsRejected(): void
    {
        // Then
        $this->expectException(InvalidOptionsException::class);

        // When
        $this->forms->create(CounterpartyPickerType::class, null, ['company_id' => '']);
    }

    public function testNonUuidCompanyIdIsRejected(): void
    {
        // Then
        $this->expectException(InvalidOptionsException::class);

        // When
        $this->forms->create(CounterpartyPickerType::class, null, ['company_id' => 'not-a-uuid']);
    }

    /**
     * После ошибки валидации другого поля форма перерисовывается: выбранный
     * контрагент обязан остаться в поле, иначе пользователь молча теряет выбор.
     */
    public function testEntityValueSurvivesRerenderAfterInvalidSubmit(): void
    {
        // Given: форма с обязательным вторым полем
        $own = $this->persist($this->company, 1, 'ООО "Ромашка"', '7707083893');
        $this->em->flush();

        // csrf_protection выключен: в интеграционном тесте нет сессии, а проверяем
        // мы перерисовку поля, а не CSRF.
        $form = $this->forms->createBuilder(FormType::class, null, ['csrf_protection' => false])
            ->add('title', TextType::class, ['constraints' => [new NotBlank()]])
            ->add('counterparty', CounterpartyPickerType::class, [
                'company_id' => $this->company->getId(),
                'value_type' => 'entity',
            ])
            ->getForm();

        // When: контрагент выбран, обязательное поле пустое
        $form->submit(['title' => '', 'counterparty' => $own]);

        // Then
        self::assertFalse($form->isValid());
        self::assertInstanceOf(Counterparty::class, $form->get('counterparty')->getData());

        $view = $form->createView();
        self::assertSame($own, $view['counterparty']->vars['value']);
        self::assertStringContainsString('7707083893', $view['counterparty']->vars['choices'][0]->label);
    }

    public function testViewCarriesSearchUrl(): void
    {
        // When
        $view = $this->forms->create(CounterpartyPickerType::class, null, [
            'company_id' => $this->company->getId(),
            'search_url' => '/api/counterparties/search',
        ])->createView();

        // Then
        self::assertSame('/api/counterparties/search', $view->vars['search_url']);
    }

    private function persist(Company $company, int $index, string $name, ?string $inn): string
    {
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->withCompany($company)
            ->withName($name)
            ->withInn($inn)
            ->build();

        $this->em->persist($counterparty);

        return $counterparty->getId();
    }

    private function persistArchived(Company $company, int $index, string $name): string
    {
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->withCompany($company)
            ->withName($name)
            ->withInn(null)
            ->asArchived()
            ->build();

        $this->em->persist($counterparty);

        return $counterparty->getId();
    }
}
