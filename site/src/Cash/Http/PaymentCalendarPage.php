<?php

declare(strict_types=1);

namespace App\Cash\Http;

use App\Cash\Application\MaterializeRecurringPaymentPlansAction;
use App\Cash\Domain\Identifier\CanonicalUuid;
use App\Company\Entity\Company;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PaymentCalendarPage extends AbstractController
{
    public function __construct(
        private readonly MaterializeRecurringPaymentPlansAction $materializeRecurringPlans,
        private readonly \App\Cash\Query\PaymentCalendarQuery $query,
        private readonly CashListPagination $pagination,
    ) {
    }

    /** @param array<string, mixed> $extra
     * @param array<string, string>|null $filters
     */
    public function renderCalendar(Request $request, Company $company, array $extra, ?array $filters = null): Response
    {
        $filters ??= $this->extractFilters($request);
        $from = $this->parseDate($filters['from']);
        $to = $this->parseDate($filters['to']);
        if (null === $from || null === $to) {
            $this->addFlash('danger', 'Пожалуйста, укажите период (from/to).');
        } elseif ($from > $to) {
            $this->addFlash('danger', 'Дата начала периода не может быть позже даты окончания.');
            $from = $to = null;
        } else {
            ($this->materializeRecurringPlans)($company, $from, $to);
        }
        $context = ($this->query)($company, $from, $to, $filters);
        $plansQuery = $context['plansQuery'];
        $adapter = $plansQuery instanceof \Doctrine\ORM\QueryBuilder ? new \Pagerfanta\Doctrine\ORM\QueryAdapter($plansQuery) : new \Pagerfanta\Adapter\ArrayAdapter([]);
        $pager = ($this->pagination)($request, $adapter);
        unset($context['plansQuery']);
        $context['pager'] = $pager;
        $context['plans'] = iterator_to_array($pager->getCurrentPageResults());
        $context['filterQuery'] = $this->buildFilterQuery($filters);

        return $this->render('payment_calendar/index.html.twig', array_merge($context, $extra));
    }

    /**
     * Невалидный UUID в фильтре игнорируется: иначе PostgreSQL падает на
     * приведении к uuid и пользователь получает 500.
     */
    private function uuidFilter(Request $request, string $name): string
    {
        $value = (string) $request->query->get($name, '');

        return CanonicalUuid::isValid($value) ? $value : '';
    }

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     account_id: string,
     *     category_id: string,
     *     status: string,
     *     counterparty_id: string
     * }
     */
    public function extractFilters(Request $request): array
    {
        $today = new \DateTimeImmutable('today');
        $defaultFrom = $today->modify('monday this week')->format('Y-m-d');
        $defaultTo = $today->modify('+60 days')->format('Y-m-d');
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');
        if ('' === trim($from)) {
            $from = $defaultFrom;
        }
        if ('' === trim($to)) {
            $to = $defaultTo;
        }

        return ['from' => $from, 'to' => $to, 'account_id' => $this->uuidFilter($request, 'account_id'), 'category_id' => $this->uuidFilter($request, 'category_id'), 'status' => (string) $request->query->get('status', ''), 'counterparty_id' => $this->uuidFilter($request, 'counterparty_id')];
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    /** @param array<string, string> $filters
     * @return array<string, string>
     */
    public function buildFilterQuery(array $filters): array
    {
        $result = [];
        foreach ($filters as $key => $value) {
            if ('' !== $value) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
