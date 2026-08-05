<?php

declare(strict_types=1);

namespace App\Company\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Текущий пароль',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Введите текущий пароль',
                    ]),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'Новый пароль',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Повторите новый пароль',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'Пароли не совпадают',
                'constraints' => self::plainPasswordConstraints(),
            ])
            ->add('website', TextType::class, [
                'label' => false,
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'style' => 'display:none',
                    'tabindex' => '-1',
                    'autocomplete' => 'off',
                ],
            ])
        ;
    }

    /**
     * @return list<NotBlank|Length>
     */
    private static function plainPasswordConstraints(): array
    {
        return [
            new NotBlank([
                'message' => 'Введите новый пароль',
            ]),
            new Length([
                'min' => 8,
                'minMessage' => 'Пароль должен содержать минимум {{ limit }} символов',
                // max length allowed by Symfony for security reasons
                'max' => 4096,
            ]),
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
