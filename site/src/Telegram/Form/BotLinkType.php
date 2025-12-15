<?php

namespace App\Telegram\Form;

use App\Entity\Company;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Repository\TelegramBotRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class BotLinkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Разрешаем выбирать только ботов текущей компании
        $builder
            ->add('bot', EntityType::class, [
                'class' => TelegramBot::class,
                'choice_label' => function (TelegramBot $bot): string {
                    $username = $bot->getUsername() ?: 'без username';

                    return sprintf('%s (%s)', $username, $bot->getId());
                },
                'label' => 'Бот',
                'query_builder' => function (TelegramBotRepository $repository) use ($options) {
                    return $repository->createQueryBuilder('b')
                        ->andWhere('b.company = :company')
                        ->setParameter('company', $options['company'])
                        ->orderBy('b.createdAt', 'DESC');
                },
                'constraints' => [
                    new NotBlank(message: 'Выберите бота'),
                ],
                'placeholder' => 'Выберите бота',
            ])
            // Срок действия ссылки
            ->add('expiresAt', DateTimeType::class, [
                'label' => 'Действует до',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [
                    new NotBlank(message: 'Укажите срок действия'),
                ],
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Создать ссылку',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Передаём текущую компанию, чтобы ограничить список ботов
        $resolver->setDefaults([
            'data_class' => null,
        ]);

        $resolver->setRequired('company');
        $resolver->setAllowedTypes('company', [Company::class]);
    }
}
