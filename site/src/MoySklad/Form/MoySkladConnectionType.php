<?php

declare(strict_types=1);

namespace App\MoySklad\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class MoySkladConnectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Название подключения',
            'constraints' => [new NotBlank(), new Length(max: 255)],
        ]);
        if ($options['is_edit']) {
            $builder->add('version', HiddenType::class, ['constraints' => [new Regex('/^[1-9][0-9]*$/')]]);
        } else {
            $builder->add('token', PasswordType::class, [
                'label' => 'Токен доступа',
                'always_empty' => true,
                'trim' => true,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [new NotBlank(), new Length(max: 8192), new Regex(pattern: '/^[\x21-\x7E]+$/D', message: 'Токен должен содержать только печатные ASCII-символы без пробелов.')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'is_edit' => false]);
        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
