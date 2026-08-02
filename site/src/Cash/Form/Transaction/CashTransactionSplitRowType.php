<?php

declare(strict_types=1);

namespace App\Cash\Form\Transaction;

use App\Cash\Application\DTO\CashTransactionSplitInput;
use App\Cash\Entity\Transaction\CashflowCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Строка формы разбивки: статья ДДС и сумма.
 *
 * Категории приходят готовым списком от родительской формы — иначе на каждую строку
 * уходил бы отдельный запрос за деревом статей.
 */
final class CashTransactionSplitRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<CashflowCategory> $categories */
        $categories = $options['categories'];

        // choices — идентификаторы, а не сущности: DTO хранит ?string, и модельным
        // значением ChoiceType должна быть та же строка. С объектами в choices поле
        // не смапилось бы ни на GET, ни на POST.
        // choices — список идентификаторов, подписи отдельной картой. Имена категорий
        // как ключи массива использовать нельзя: две одноимённые статьи в разных ветках
        // дали бы один ключ, и одна молча вытеснила бы другую из формы.
        $choices = [];
        $labels = [];
        $disabled = [];
        foreach ($categories as $category) {
            $id = (string) $category->getId();
            $choices[] = $id;
            // Подпись — полный путь: одноимённые статьи в разных ветках иначе неразличимы.
            $labels[$id] = str_repeat("\u{a0}", $category->getLevel() - 1).$this->pathOf($category);
            // Узлы с детьми выбирать нельзя: суммы вешаются на листья, иначе одна и та же
            // сумма попадёт и в родителя, и в ребёнка при свёртке дерева.
            $disabled[$id] = !$category->getChildren()->isEmpty();
        }

        $builder
            ->add('cashflowCategoryId', ChoiceType::class, [
                'label' => 'Статья ДДС',
                'placeholder' => 'Выберите статью',
                'choices' => $choices,
                'choice_label' => static fn (string $id) => $labels[$id] ?? $id,
                'choice_attr' => static fn (string $id) => ($disabled[$id] ?? false) ? ['disabled' => 'disabled'] : [],
            ])
            ->add('amount', TextType::class, [
                'label' => 'Сумма',
                // Класс задаётся здесь и не переопределяется в шаблоне: перезапись attr
                // выкинула бы его, и JS перестал бы видеть уже отрисованные строки.
                'attr' => ['inputmode' => 'decimal', 'class' => 'form-control text-end js-split-amount'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CashTransactionSplitInput::class,
        ]);
        $resolver->setRequired('categories');
        $resolver->setAllowedTypes('categories', 'array');
    }

    /**
     * Путь категории от корня: «Расходы / Офис / Аренда».
     */
    private function pathOf(CashflowCategory $category): string
    {
        $names = [];
        $node = $category;
        for ($depth = 0; $depth < 10 && null !== $node; ++$depth) {
            array_unshift($names, $node->getName());
            $node = $node->getParent();
        }

        return implode(' / ', $names);
    }
}
