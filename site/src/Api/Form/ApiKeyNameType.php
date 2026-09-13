<?php

declare(strict_types=1);

namespace App\Api\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array{name: string}> */
final class ApiKeyNameType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, ['label' => 'Название ключа', 'trim' => true, 'empty_data' => '', 'attr' => ['maxlength' => 255, 'autocomplete' => 'off'], 'constraints' => [new NotBlank(message: 'Введите название ключа.'), new Length(max: 255, maxMessage: 'Название должно содержать не более 255 символов.')]]);
    }
}
