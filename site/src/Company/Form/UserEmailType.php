<?php

declare(strict_types=1);

namespace App\Company\Form;

use App\Company\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Webmozart\Assert\Assert;

/**
 * Поле email, привязанное к User::$email.
 *
 * Symfony маппит данные формы в сущность до запуска валидатора, поэтому constraints
 * на поле не успевают сработать: User::setEmail() бросает InvalidArgumentException
 * и запрос падает с 500. TransformationFailedException — единственное исключение,
 * которое Form::submit() перехватывает сам, превращая его в ошибку формы.
 *
 * Предикат намеренно тот же Assert::email(), что и в User::setEmail(): одно определение
 * «валидный email» на форму и на сущность.
 *
 * Родитель — TextType, а не EmailType: HTML5-валидация type="email" разрешает только ASCII
 * и отвергла бы «иван@example.com», который Assert::email() с FILTER_FLAG_EMAIL_UNICODE
 * принимает. Клиентский гейт не должен быть строже серверного предиката.
 */
final class UserEmailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?string $email): string => $email ?? '',
            static function (?string $email): string {
                $normalized = User::normalizeEmail((string) $email);

                try {
                    Assert::email($normalized);
                } catch (\InvalidArgumentException) {
                    // Без previous и без значения: сообщение Assert содержит сам email,
                    // а исключение оседает в профайлере и телеметрии.
                    throw new TransformationFailedException('Invalid email address.');
                }

                return $normalized;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'invalid_message' => 'Введите корректный email',
        ]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
