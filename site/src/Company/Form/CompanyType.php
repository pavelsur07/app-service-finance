<?php

declare(strict_types=1);

namespace App\Company\Form;

use App\Company\Entity\Company;
use App\Company\Enum\CompanyTaxSystem;
use App\Shared\Form\Type\MoneyValueType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CompanyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Наименование компании',
                'required' => true,
            ])
            ->add('inn', TextType::class, [
                'label' => 'ИНН',
                'required' => false,
            ])
            ->add('taxSystem', ChoiceType::class, [
                'label' => 'Налоговый режим (НДС)',
                'choices' => CompanyTaxSystem::cases(),
                'choice_label' => static fn (CompanyTaxSystem $choice) => $choice->label(),
                'placeholder' => 'Не выбран',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('financeLockBefore', DateType::class, [
                'widget' => 'single_text',
                'required' => false,
                'label' => 'Дата запрета редактирования',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('minimumBalance', MoneyValueType::class);

        // От профиля зависят стартовые шаблоны. Смена в настройках справочники не перестраивает:
        // «Базовый шаблон ОПиУ» берёт шаблон нового профиля и не применяется к дереву из другого.
        $company = $options['data'] ?? null;
        $builder->add('businessProfile', BusinessProfileType::class, $options['business_profile_help'] && $company instanceof Company
            ? [
                'help' => 'Справочники компании не изменятся. «Базовый шаблон ОПиУ» будет брать шаблон нового профиля; к дереву ОПиУ, созданному шаблоном другого профиля, он не применится.',
                // Запрос без поля не меняет профиль: значение по умолчанию — текущий, а не DEFAULT.
                'empty_data' => $company->getBusinessProfile()->value,
            ]
            : []);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Company::class,
            'business_profile_help' => false,
        ]);
        $resolver->setAllowedTypes('business_profile_help', 'bool');
    }
}
