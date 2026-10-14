<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

trait FormatsPaginatedResponse
{
    /**
     * Format a paginated collection and its pagination metadata into a standard JSON response.
     *
     * @param  LengthAwarePaginator<mixed>  $paginator
     * @param  array<string, mixed>  $extra
     */
    protected function paginatedResponse(LengthAwarePaginator $paginator, mixed $items = null, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => true,
            'data' => [
                'items' => $items ?? $paginator->items(),
                'pagination' => $this->formatPagination($paginator),
            ],
        ], $extra));
    }

    /**
     * Extract standard pagination metadata from a paginator instance.
     *
     * @param  LengthAwarePaginator<mixed>  $paginator
     * @return array{total: int, per_page: int, current_page: int, last_page: int, from: ?int, to: ?int}
     */
    protected function formatPagination(LengthAwarePaginator $paginator): array
    {
        return [
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
