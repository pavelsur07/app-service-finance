<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Company\Entity\User;
use App\Company\Form\RegistrationFormType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AdminAccountCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class)
            ->add('companyName', TextType::class, [
                'label' => 'Имя компании',
                'mapped' => false,
                'required' => true,
                'attr' => [
                    'placeholder' => 'ООО Ромашка',
                ],
                'constraints' => RegistrationFormType::companyNameConstraints(),
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => RegistrationFormType::plainPasswordConstraints(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
