<?php

namespace App\Form;

use App\Entity\Company;
use Symfony\Component\Form\AbstractType;
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
            ->add('wildberriesApiKey', TextType::class, [
                'label' => 'Wildberries API ключ',
                'required' => false,
            ])
            ->add('ozonSellerId', TextType::class, [
                'label' => 'Ozon Seller ID',
                'required' => false,
            ])
            ->add('ozonApiKey', TextType::class, [
                'label' => 'Ozon API ключ',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Company::class,
        ]);
    }
}
