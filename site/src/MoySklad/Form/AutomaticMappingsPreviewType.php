<?php

declare(strict_types=1);

namespace App\MoySklad\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string,mixed>> */
final class AutomaticMappingsPreviewType extends AbstractType
{
    public static function csrfId(string $connection, string $marketplace, string $charQ, int $charPage, ?string $selected): string
    {
        return 'moysklad_automatic_preview'.$connection.$marketplace.hash('sha256', json_encode([$charQ, $charPage, $selected], \JSON_THROW_ON_ERROR));
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<array{id:string,name:string}> $items */
        $items = $options['characteristics'];
        $labels = array_column($items, 'name', 'id');
        $builder->add('sizeCharacteristicId', ChoiceType::class, ['label' => 'Характеристика размера МойСклад', 'required' => false,
            'placeholder' => 'Без правила размера', 'choices' => array_column($items, 'id'),
            'choice_label' => static fn (string $id): string => $labels[$id].' · '.$id]);
        $builder->add('q', HiddenType::class, ['constraints' => [new Assert\Length(max: 100)]]);
        $builder->add('page', HiddenType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Regex('/^[1-9][0-9]*$/D'), new Assert\Range(min: 1, max: 2147483647)]]);
        $builder->add('_token', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => false, 'data_class' => null, 'characteristics' => []]);
        $resolver->setAllowedTypes('characteristics', 'array');
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
