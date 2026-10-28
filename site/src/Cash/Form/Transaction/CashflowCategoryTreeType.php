<?php

declare(strict_types=1);

namespace App\Cash\Form\Transaction;

use App\Cash\Application\Service\CashflowCategoryTreeBuilder;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashDirection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Выбор статьи ДДС: обычный select (он же работает без JS и ограничивает
 * значения при submit) + дерево для виджета в `vars.tree`.
 *
 * Список статей передаёт вызывающий в `choices` (`findTreeByCompany()` своей
 * компании) — тип в БД не ходит, дерево строится из тех же статей, что и select.
 *
 * @extends AbstractType<CashflowCategory|null>
 */
final class CashflowCategoryTreeType extends AbstractType
{
    public function __construct(private readonly CashflowCategoryTreeBuilder $treeBuilder)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'choice_label' => static fn (CashflowCategory $c): string => str_repeat("\u{a0}", $c->getLevel() - 1).$c->getName(),
            'choice_value' => 'id',
            // Группу выбрать нельзя: в select она disabled, в виджете только раскрывается.
            'choice_attr' => static fn (CashflowCategory $c): array => !$c->getChildren()->isEmpty() ? ['disabled' => 'disabled'] : [],
            'choice_translation_domain' => false,
            // Имя соседнего поля направления (CashDirection): виджет фильтрует статьи по притоку/оттоку.
            'direction_field' => null,
        ]);
        $resolver->setAllowedTypes('direction_field', ['null', 'string']);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        /** @var iterable<CashflowCategory> $choices */
        $choices = $options['choices'];
        $view->vars['tree'] = $this->treeBuilder->build($choices);
        $view->vars['direction'] = null;
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        // Вид соседа уже есть, если поле направления добавлено в форму раньше статьи;
        // иначе виджет просто работает без фильтра. Значения option — индексы
        // ChoiceType, поэтому приток/отток ищем по данным.
        $direction = null !== $options['direction_field'] ? ($view->parent?->children[$options['direction_field']] ?? null) : null;
        if (null === $direction) {
            return;
        }

        $values = [];
        foreach ($direction->vars['choices'] ?? [] as $choice) {
            if ($choice instanceof ChoiceView && $choice->data instanceof CashDirection) {
                $values[$choice->data->value] = $choice->value;
            }
        }
        if (isset($values[CashDirection::INFLOW->value], $values[CashDirection::OUTFLOW->value])) {
            $view->vars['direction'] = [
                'source' => sprintf('[name="%s"]', $direction->vars['full_name']),
                'in' => $values[CashDirection::INFLOW->value],
                'out' => $values[CashDirection::OUTFLOW->value],
            ];
        }
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'cashflow_category_tree';
    }
}
