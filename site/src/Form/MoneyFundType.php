<?php

namespace App\Form;

use App\Entity\MoneyFund;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MoneyFundType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Название',
            ])
            ->add('currency', TextType::class, [
                'label' => 'Валюта',
                'attr' => ['maxlength' => 3, 'class' => 'text-uppercase'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MoneyFund::class,
        ]);
    }
}
