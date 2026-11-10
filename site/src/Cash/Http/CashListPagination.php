<?php

declare(strict_types=1);

namespace App\Cash\Http;

use Pagerfanta\Adapter\AdapterInterface;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class CashListPagination
{
    /** @template T
     * @param AdapterInterface<T> $adapter
     *
     * @return Pagerfanta<T>
     */
    public function __invoke(Request $request, AdapterInterface $adapter): Pagerfanta
    {
        $query = $request->query->all();
        $page = $this->positiveInteger($query['page'] ?? 1);
        $limit = $this->positiveInteger($query['limit'] ?? 50);
        if ($limit > 200) {
            throw new UnprocessableEntityHttpException('Размер страницы не может превышать 200.');
        }
        $pager = new Pagerfanta($adapter);
        try {
            $pager->setMaxPerPage($limit)->setCurrentPage($page);
        } catch (OutOfRangeCurrentPageException $exception) {
            throw new UnprocessableEntityHttpException('Запрошенная страница недоступна.', $exception);
        }

        return $pager;
    }

    private function positiveInteger(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value)) || false === ($integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))) {
            throw new UnprocessableEntityHttpException('page и limit должны быть положительными целыми числами.');
        }

        return $integer;
    }
}
