<?php

declare(strict_types=1);

namespace App\Api\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array{secret: string}> */
final class ApiKeyCheckType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('secret', PasswordType::class, ['label' => 'API-ключ', 'always_empty' => true, 'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'], 'constraints' => [new NotBlank(message: 'Вставьте API-ключ для проверки.')]]);
    }
}
