<?php

declare(strict_types=1);

namespace App\Company\Form;

use App\Company\Application\DTO\CounterpartyFormData;
use App\Company\Enum\CounterpartyType as CounterpartyTypeEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CounterpartyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Наименование',
            ])
            ->add('inn', TextType::class, [
                'label' => 'ИНН',
                'required' => false,
                'help' => '10 или 12 цифр',
            ])
            ->add('kpp', TextType::class, [
                'label' => 'КПП',
                'required' => false,
                'help' => '9 цифр, только вместе с ИНН',
            ])
            ->add('type', ChoiceType::class, [
                'choices' => [
                    'Юридическое лицо' => CounterpartyTypeEnum::LEGAL_ENTITY,
                    'Индивидуальный предприниматель' => CounterpartyTypeEnum::INDIVIDUAL_ENTREPRENEUR,
                    'Самозанятый' => CounterpartyTypeEnum::SELF_EMPLOYED,
                    'Физическое лицо' => CounterpartyTypeEnum::NATURAL_PERSON,
                ],
                'label' => 'Тип',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CounterpartyFormData::class,
        ]);
    }
}
