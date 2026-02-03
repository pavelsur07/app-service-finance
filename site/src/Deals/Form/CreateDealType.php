<?php

declare(strict_types=1);

namespace App\Deals\Form;

use App\Company\Entity\Company;
use App\Deals\DTO\CreateDealFormData;
use App\Deals\Enum\DealChannel;
use App\Deals\Enum\DealType;
use App\Entity\Counterparty;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
        /** @var Company|null $company */
        $company = $options['company'];

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
            ->add('counterpartyId', EntityType::class, [
                'class' => Counterparty::class,
                'label' => 'Контрагент',
                'required' => false,
                'placeholder' => 'Без контрагента',
                'choice_label' => static fn (Counterparty $counterparty): string => (string) $counterparty->getName(),
                'query_builder' => static function (EntityRepository $repository) use ($company) {
                    $qb = $repository->createQueryBuilder('counterparty')
                        ->orderBy('counterparty.name', 'ASC');

                    if ($company) {
                        $qb->andWhere('counterparty.company = :company')
                            ->setParameter('company', $company);
                    }

                    return $qb;
                },
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateDealFormData::class,
            'company' => null,
        ]);
        $resolver->setAllowedTypes('company', [Company::class, 'null']);
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
