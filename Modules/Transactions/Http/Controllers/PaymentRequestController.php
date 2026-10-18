<?php

namespace Modules\Transactions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Transactions\Http\Requests\CreatePaymentRequestRequest;
use Modules\Transactions\Resources\PaymentRequestResource;
use Modules\Transactions\Services\PaymentRequestService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PaymentRequestController extends Controller
{
    public function __construct(
        protected PaymentRequestService $paymentRequests,
    ) {}

    /**
     * The caller's requests: ones they were asked to pay (incoming) or ones they sent (outgoing).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'direction' => ['sometimes', 'string', 'in:incoming,outgoing'],
            'status' => ['sometimes', 'string', 'in:PENDING,PAID,DECLINED,CANCELLED,EXPIRED'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $userId = $request->user()->id;

        $query = PaymentRequest::with(['requester', 'payer'])->latest('id');
        match ($validated['direction'] ?? null) {
            'incoming' => $query->where('payer_id', $userId),
            'outgoing' => $query->where('requester_id', $userId),
            default => $query->where(fn ($q) => $q->where('payer_id', $userId)->orWhere('requester_id', $userId)),
        };

        $status = $validated['status'] ?? null;
        if ($status === PaymentRequest::PENDING) {
            $query->where('status', PaymentRequest::PENDING)->where('expires_at', '>', now());
        } elseif ($status === PaymentRequest::EXPIRED) {
            // Expired includes pending requests the daily sweep has not closed yet
            $query->where(fn ($q) => $q->where('status', PaymentRequest::EXPIRED)
                ->orWhere(fn ($open) => $open->where('status', PaymentRequest::PENDING)->where('expires_at', '<=', now())));
        } elseif ($status !== null) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 15));

        return $this->paginatedResponse($paginator, PaymentRequestResource::collection($paginator));
    }

    /**
     * Ask another customer for money.
     */
    public function store(CreatePaymentRequestRequest $request): JsonResponse
    {
        $paymentRequest = $this->paymentRequests->create(
            requester: $request->user(),
            payerPhone: $request->validated('payer_phone'),
            amount: $request->validated('amount'),
            note: $request->validated('note'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Request sent. The customer gets an SMS and can pay it from their app.',
            'data' => ['payment_request' => new PaymentRequestResource($paymentRequest->load(['requester', 'payer']))],
        ], 201);
    }

    /**
     * Pay a request addressed to the caller.
     */
    public function pay(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $this->assertParty($request, $paymentRequest);
        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits_between:5,10'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        $paid = $this->paymentRequests->pay($paymentRequest, $request->user(), $validated['pin'], $validated['idempotency_key']);

        return $this->respond($paid, 'Request paid.');
    }

    /**
     * Decline a request addressed to the caller.
     */
    public function decline(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $this->assertParty($request, $paymentRequest);

        return $this->respond($this->paymentRequests->decline($paymentRequest, $request->user()), 'Request declined.');
    }

    /**
     * Cancel one of the caller's own requests.
     */
    public function cancel(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $this->assertParty($request, $paymentRequest);

        return $this->respond($this->paymentRequests->cancel($paymentRequest, $request->user()), 'Request cancelled.');
    }

    /**
     * Requests are private to their two parties; anyone else gets a 404, not a 403.
     */
    private function assertParty(Request $request, PaymentRequest $paymentRequest): void
    {
        $userId = (int) $request->user()->id;
        if ((int) $paymentRequest->payer_id !== $userId && (int) $paymentRequest->requester_id !== $userId) {
            throw new NotFoundHttpException;
        }
    }

    private function respond(PaymentRequest $paymentRequest, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['payment_request' => new PaymentRequestResource($paymentRequest->load(['requester', 'payer']))],
        ]);
    }
}
