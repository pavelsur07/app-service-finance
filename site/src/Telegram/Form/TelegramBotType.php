<?php

namespace App\Telegram\Form;

use App\Entity\Company;
use App\Telegram\Entity\TelegramBot;
use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class TelegramBotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Поле токена — обязателен для заполнения
        $builder
            ->add('company', EntityType::class, [
                'label' => 'Компания',
                'class' => Company::class,
                'choice_label' => 'name',
                'placeholder' => 'Выберите компанию',
                'required' => true,
                'disabled' => $options['lock_company'],
                'constraints' => [
                    new NotBlank(message: 'Выберите компанию'),
                ],
            ])
            ->add('token', TextType::class, [
                'label' => 'Токен',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Укажите токен бота'),
                ],
            ])
            // Имя пользователя в Telegram — опционально
            ->add('username', TextType::class, [
                'label' => 'Username (опционально)',
                'required' => false,
            ])
            // URL вебхука — опционально
            ->add('webhookUrl', TextType::class, [
                'label' => 'Webhook URL (опционально)',
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
            'lock_company' => false,
            'empty_data' => function (FormInterface $form) {
                /**
                 * Админка платформы не зависит от active_company_id, поэтому собираем сущность
                 * из данных формы без ActiveCompanyService.
                 */
                $company = $form->get('company')->getData();
                $token = (string) $form->get('token')->getData();
                $username = $form->get('username')->getData();
                $webhookUrl = $form->get('webhookUrl')->getData();
                $isActive = (bool) $form->get('isActive')->getData();

                $bot = new TelegramBot(Uuid::uuid4()->toString(), $company, $token);
                $bot->setUsername($username);
                $bot->setWebhookUrl($webhookUrl);
                $bot->setIsActive($isActive);

                return $bot;
            },
        ]);

        $resolver->setAllowedTypes('lock_company', ['bool']);
    }
}
