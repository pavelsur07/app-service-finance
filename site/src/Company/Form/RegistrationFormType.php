<?php

declare(strict_types=1);

namespace App\Company\Form;

use App\Company\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$options['is_invite']) {
            $builder->add('companyName', TextType::class, [
                'label' => 'Имя компании',
                'mapped' => false,
                'required' => true,
                'attr' => [
                    'placeholder' => 'ООО Ромашка',
                ],
                'constraints' => self::companyNameConstraints(),
            ]);
        }

        $builder
            ->add('email')
            ->add('agreeTerms', CheckboxType::class, [
                'label' => 'Я принимаю Пользовательское соглашение и Политику конфиденциальности',
                'mapped' => false,
                'constraints' => [
                    new IsTrue([
                        'message' => 'You should agree to our terms.',
                    ]),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                // instead of being set onto the object directly,
                // this is read and encoded in the controller
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password'],
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
     * @return list<NotBlank>
     */
    public static function companyNameConstraints(): array
    {
        return [
            new NotBlank([
                'message' => 'Введите имя компании',
            ]),
        ];
    }

    /**
     * @return list<NotBlank|Length>
     */
    public static function plainPasswordConstraints(): array
    {
        return [
            new NotBlank([
                'message' => 'Please enter a password',
            ]),
            new Length([
                'min' => 6,
                'minMessage' => 'Your password should be at least {{ limit }} characters',
                // max length allowed by Symfony for security reasons
                'max' => 4096,
            ]),
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_invite' => false,
        ]);
    }
}
