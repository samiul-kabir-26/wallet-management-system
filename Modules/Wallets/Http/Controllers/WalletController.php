<?php

namespace Modules\Wallets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Wallets\Http\Requests\BlockWalletRequest;
use Modules\Wallets\Resources\WalletResource;
use Modules\Wallets\Services\WalletService;

class WalletController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private WalletService $walletService,
    ) {}

    /**
     * Get the authenticated user's own wallet.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $wallet = $this->walletService->getWalletForUser($actor);

        $this->authorize('view', $wallet);

        return response()->json([
            'success' => true,
            'data' => [
                'wallet' => new WalletResource($wallet),
            ],
        ]);
    }

    /**
     * List all wallets with optional filtering and pagination (Admin only).
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Wallet::class);

        $validated = $request->validate([
            'is_blocked' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);

        $paginator = $this->walletService->listWallets($validated, $perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'wallets' => WalletResource::collection($paginator),
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Block a specific wallet with an audit reason (Admin only).
     */
    public function block(BlockWalletRequest $request, Wallet $wallet): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $blockedWallet = $this->walletService->blockWallet(
            wallet: $wallet,
            reason: $request->validated('reason'),
            actor: $actor,
        );

        return response()->json([
            'success' => true,
            'message' => 'Wallet blocked successfully',
            'data' => [
                'wallet' => new WalletResource($blockedWallet),
            ],
        ]);
    }

    /**
     * Unblock a specific wallet (Admin only).
     */
    public function unblock(Request $request, Wallet $wallet): JsonResponse
    {
        $this->authorize('unblock', $wallet);

        /** @var User $actor */
        $actor = $request->user();

        $unblockedWallet = $this->walletService->unblockWallet(
            wallet: $wallet,
            actor: $actor,
        );

        return response()->json([
            'success' => true,
            'message' => 'Wallet unblocked successfully',
            'data' => [
                'wallet' => new WalletResource($unblockedWallet),
            ],
        ]);
    }
}
