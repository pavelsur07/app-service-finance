<?php

declare(strict_types=1);

namespace App\Deals\Form;

use App\Company\Entity\Company;
use App\Company\Form\Type\CounterpartyPickerType;
use App\Deals\DTO\CreateDealFormData;
use App\Deals\Enum\DealChannel;
use App\Deals\Enum\DealType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CreateDealType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company $company */
        $company = $options['company'];
        $companyId = (string) $company->getId();

        $builder
            ->add('recognizedAt', DateType::class, [
                'label' => 'Дата признания',
                'widget' => 'single_text',
            ])
            ->add('title', TextType::class, [
                'label' => 'Название',
                'required' => false,
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Тип',
                'choices' => $this->buildTypeChoices(),
            ])
            ->add('channel', ChoiceType::class, [
                'label' => 'Канал',
                'choices' => $this->buildChannelChoices(),
            ])
            ->add('counterpartyId', CounterpartyPickerType::class, [
                'company_id' => $companyId,
                'keep_id' => $builder->getData()?->counterpartyId?->getId(),
                'value_type' => 'entity',
                'search_url' => '/api/counterparties/search',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateDealFormData::class,
        ]);
        // company обязателен: раньше опция по умолчанию была null, и tenant-фильтр
        // стоял под условием — забытая опция открывала справочник всех компаний.
        $resolver->setRequired('company');
        $resolver->setAllowedTypes('company', Company::class);
    }

    /**
     * @return array<string, DealType>
     */
    private function buildTypeChoices(): array
    {
        $choices = [];

        foreach (DealType::cases() as $type) {
            $choices[$type->value] = $type;
        }

        return $choices;
    }

    /**
     * @return array<string, DealChannel>
     */
    private function buildChannelChoices(): array
    {
        $choices = [];

        foreach (DealChannel::cases() as $channel) {
            $choices[$channel->value] = $channel;
        }

        return $choices;
    }
}
