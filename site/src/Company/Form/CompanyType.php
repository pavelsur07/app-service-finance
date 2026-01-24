<?php

namespace App\Company\Form;

use App\Entity\Company;
use App\Enum\CompanyTaxSystem;
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Company::class,
        ]);
    }
}
