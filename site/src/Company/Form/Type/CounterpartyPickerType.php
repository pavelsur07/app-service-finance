<?php

declare(strict_types=1);

namespace App\Company\Form\Type;

use App\Company\Facade\CounterpartyFacade;
use App\Company\Facade\DTO\CounterpartyChoiceDTO;
use App\Company\Form\DataTransformer\CounterpartyEntityTransformer;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Единственный способ выбрать контрагента в форме.
 *
 * `company_id` обязателен: фильтр по компании не может быть забыт, потому что без
 * опции форма просто не собирается. Варианты приходят из фасада как DTO, поэтому
 * Entity чужого модуля в формы не попадает, а `ChoiceType` отклоняет любое значение
 * вне company-scoped списка — это и есть защита от подстановки чужого id.
 *
 * `value_type: 'entity'` — для форм, привязанных к сущности: маппинг делает
 * DataTransformer, а не слушатель формы.
 */
final class CounterpartyPickerType extends AbstractType
{
    public function __construct(private readonly CounterpartyFacade $facade)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ('entity' === $options['value_type']) {
            $builder->addModelTransformer(
                new CounterpartyEntityTransformer($this->facade, $options['company_id']),
            );
        }
    }

    /**
     * @param array{allow_create: bool, search_url: string|null, counterparty_choices: list<CounterpartyChoiceDTO>} $options
     */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['allow_create'] = $options['allow_create'];
        $view->vars['search_url'] = $options['search_url'];
        $view->vars['counterparties'] = $options['counterparty_choices'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('company_id');
        $resolver->setAllowedTypes('company_id', 'string');
        // Пустая строка раньше проходила проверку типа и молча давала пустой список:
        // форма выглядела рабочей, но выбранное значение исчезало.
        $resolver->setAllowedValues('company_id', static fn (string $companyId): bool => Uuid::isValid($companyId));

        $resolver->setDefined(['keep_id', 'value_type', 'allow_create', 'search_url', 'counterparty_choices']);
        $resolver->setAllowedTypes('keep_id', ['string', 'null']);
        $resolver->setAllowedValues('value_type', ['id', 'entity']);
        $resolver->setAllowedTypes('allow_create', 'bool');
        $resolver->setAllowedTypes('search_url', ['string', 'null']);

        $resolver->setDefaults([
            'keep_id' => null,
            'value_type' => 'id',
            'allow_create' => false,
            'search_url' => null,
            'required' => false,
            'placeholder' => 'Без контрагента',
            'label' => 'Контрагент',
            // Полный company-scoped список: он же no-JS fallback и он же граница
            // допустимых значений при submit. Заполняется нормализатором ниже.
            'counterparty_choices' => [],
            // Список ID, подпись — отдельным lookup: индексация массива подписью
            // затирала бы контрагентов с одинаковым названием и без ИНН.
            'choices' => static fn (Options $options): array => array_map(
                static fn (CounterpartyChoiceDTO $choice): string => $choice->id,
                $options['counterparty_choices'],
            ),
            'choice_label' => function (Options $options): callable {
                $byId = self::indexById($options['counterparty_choices']);

                return static fn (string $id): string => $byId[$id]?->label() ?? $id;
            },
            'choice_attr' => function (Options $options): callable {
                $byId = self::indexById($options['counterparty_choices']);

                return static function (string $id) use ($byId): array {
                    $choice = $byId[$id] ?? null;

                    return [
                        'data-name' => $choice?->name ?? '',
                        'data-inn' => $choice?->inn ?? '',
                    ];
                };
            },
        ]);

        // counterparty_choices считаются после company_id/keep_id — нормализатором,
        // иначе фасад пришлось бы дёргать в каждой форме.
        $resolver->setNormalizer(
            'counterparty_choices',
            /** @return list<CounterpartyChoiceDTO> */
            function (Options $options, mixed $value): array {
                if (is_array($value) && [] !== $value) {
                    /** @var list<CounterpartyChoiceDTO> $value */
                    return $value;
                }

                return $this->facade->getSelectable($options['company_id'], $options['keep_id']);
            },
        );
    }

    /**
     * @param list<CounterpartyChoiceDTO> $choices
     *
     * @return array<string, CounterpartyChoiceDTO>
     */
    private static function indexById(array $choices): array
    {
        $byId = [];
        foreach ($choices as $choice) {
            $byId[$choice->id] = $choice;
        }

        return $byId;
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'counterparty_picker';
    }
}
