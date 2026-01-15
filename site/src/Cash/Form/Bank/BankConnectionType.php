<?php

namespace App\Cash\Form\Bank;

use App\Cash\Entity\Bank\BankConnection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BankConnectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('bankCode', ChoiceType::class, [
                'choices' => [
                    'Альфа-Банк' => 'alfa',
                ],
                'label' => 'Банк',
                'required' => true,
            ])
            ->add('baseUrl', ChoiceType::class, [
                'choices' => [
                    'Песочница (sandbox.alfabank.ru)' => 'https://sandbox.alfabank.ru',
                    'Прод (baas.alfabank.ru)' => 'https://baas.alfabank.ru',
                ],
                'label' => 'Base URL',
                'required' => true,
            ])
            ->add('apiKey', PasswordType::class, [
                'label' => 'API ключ',
                'required' => $options['require_api_key'],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Активно',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BankConnection::class,
            'require_api_key' => true,
        ]);

        $resolver->setAllowedTypes('require_api_key', 'bool');
    }
}
