<?php

namespace App\Telegram\Form;

use App\Telegram\Entity\TelegramBot;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class TelegramBotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Поле токена — обязателен для заполнения
        $builder
            ->add('token', TextType::class, [
                'label' => 'Токен',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Укажите токен бота'),
                ],
            ])
            // Имя пользователя в Telegram — опционально
            ->add('username', TextType::class, [
                'label' => 'Имя пользователя (опционально)',
                'required' => false,
            ])
            // URL вебхука — опционально
            ->add('webhookUrl', TextType::class, [
                'label' => 'URL вебхука (опционально)',
                'required' => false,
            ])
            // Флаг активности — переключение включён/выключен
            ->add('isActive', CheckboxType::class, [
                'label' => 'Активен',
                'required' => false,
            ])
            // Стандартная кнопка сохранения для единообразия форм
            ->add('save', SubmitType::class, [
                'label' => 'Сохранить',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Указываем связанную сущность формы
        $resolver->setDefaults([
            'data_class' => TelegramBot::class,
        ]);
    }
}
