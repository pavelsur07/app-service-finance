<?php

declare(strict_types=1);

namespace App\MoySklad\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class MoySkladConnectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Название подключения',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('baseUrl', TextType::class, [
                'label' => 'Base URL',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('login', TextType::class, [
                'label' => 'Логин',
                'required' => false,
            ])
            ->add('accessToken', PasswordType::class, [
                'label' => 'Access token',
                'required' => false,
                'always_empty' => false,
            ])
            ->add('refreshToken', PasswordType::class, [
                'label' => 'Refresh token',
                'required' => false,
                'always_empty' => false,
            ])
            ->add('tokenExpiresAt', DateTimeType::class, [
                'label' => 'Токен истекает',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Активно',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
