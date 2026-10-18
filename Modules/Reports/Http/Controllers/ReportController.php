<?php

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Reports\Services\DashboardMetricsService;
use Modules\Reports\Services\LedgerExportService;
use Modules\Roles\Enums\Permission;
use Modules\Transactions\Services\TransactionService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Dashboard figures for the last N business days.
     */
    public function overview(Request $request, DashboardMetricsService $metrics): JsonResponse
    {
        $this->requirePermission($request, Permission::TransactionsViewAny);

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:'.config('reports.max_days', 90)],
        ]);
        $days = (int) ($validated['days'] ?? 14);

        return response()->json([
            'success' => true,
            'data' => $metrics->overview($days),
        ]);
    }

    /**
     * Download the filtered admin ledger as CSV.
     */
    public function exportLedger(Request $request, TransactionService $transactions, LedgerExportService $export): StreamedResponse
    {
        $this->requirePermission($request, Permission::TransactionsExport);

        $filters = $request->validate([
            'type' => ['sometimes', 'string', 'in:TOP_UP,CASH_IN,CASH_OUT,TRANSFER,AGENT_WITHDRAWAL,COMMISSION_PAYOUT,FLOAT_ISSUE,SIGNUP_BONUS,REVERSAL'],
            'status' => ['sometimes', 'string', 'in:COMPLETED,PENDING,FAILED,REJECTED'],
            'search' => ['sometimes', 'string', 'max:100'],
            'party_id' => ['sometimes', 'integer', 'exists:users,id'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
        ]);

        $query = $transactions->adminLedgerQuery($filters);
        $rows = (clone $query)->count();
        $maxRows = (int) config('reports.export_max_rows', 50000);
        if ($rows > $maxRows) {
            throw ValidationException::withMessages([
                'from_date' => ["This export has {$rows} rows; narrow the filters to at most {$maxRows}."],
            ]);
        }

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'TRANSACTIONS_EXPORTED',
            'auditable_type' => null,
            'auditable_id' => null,
            'metadata' => ['filters' => $filters, 'rows' => $rows, 'ip' => $request->ip()],
        ]);

        $filename = 'ledger-'.now(config('transactions.business_timezone'))->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($export, $query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            $export->write($query, $handle);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Export-Rows' => (string) $rows,
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    private function requirePermission(Request $request, Permission $permission): void
    {
        if (! $request->user()->can($permission->value)) {
            throw new AuthorizationException('You do not have permission to view this report.');
        }
    }
}
