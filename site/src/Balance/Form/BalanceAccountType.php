<?php

declare(strict_types=1);

namespace App\Balance\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, mixed>> */
final class BalanceAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('articleId', ChoiceType::class, ['label' => 'Статья', 'choices' => $options['article_choices'], 'constraints' => [new NotBlank()]])
            ->add('name', TextType::class, ['label' => 'Название счета', 'constraints' => [new NotBlank()]])
            ->add('code', TextType::class, ['label' => 'Код', 'constraints' => [new NotBlank()]])
            ->add('allowNegative', CheckboxType::class, ['label' => 'Разрешить отрицательный остаток', 'required' => false, 'help' => 'После первого движения изменить это правило нельзя.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['article_choices' => []]);
        $resolver->setAllowedTypes('article_choices', 'array');
    }
}
