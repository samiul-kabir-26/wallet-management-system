<?php

namespace Modules\Transactions\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Modules\SystemSettings\Services\SystemSettingService;
use Modules\Wallets\Exceptions\WalletNotFoundException;

class SignupBonusService
{
    public const string SETTING_ENABLED = 'signup_bonus_enabled';

    public const string SETTING_AMOUNT = 'signup_bonus_amount';

    public const string TYPE = 'SIGNUP_BONUS';

    public function __construct(
        protected SystemSettingService $settingService,
    ) {}

    /**
     * Whether new wallets currently receive a signup bonus.
     */
    public function isEnabled(): bool
    {
        $stored = $this->settingService->get(self::SETTING_ENABLED);

        return $stored !== null
            ? (bool) $stored
            : (bool) config('transactions.signup_bonus.default_enabled', true);
    }

    /**
     * The bonus amount currently credited to new wallets, formatted to 2 decimal places.
     */
    public function amount(): string
    {
        $stored = $this->settingService->get(self::SETTING_AMOUNT);
        $amount = $stored ?? config('transactions.signup_bonus.default_amount', 50.00);

        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * The idempotency key that limits each wallet to a single signup bonus.
     */
    public static function idempotencyKey(Wallet $wallet): string
    {
        return "signup-bonus:{$wallet->id}";
    }

    /**
     * Credit the signup bonus to a newly opened wallet and record it in the ledger.
     *
     * Call inside the registration transaction so the account and its bonus commit or roll back together.
     * Returns null when the bonus is disabled or set to zero. Calling it twice for the same wallet returns
     * the original transaction instead of crediting again.
     *
     * @throws WalletNotFoundException
     */
    public function credit(Wallet $wallet): ?Transaction
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $amount = $this->amount();
        if (bccomp($amount, '0.00', 2) <= 0) {
            return null;
        }

        return DB::transaction(function () use ($wallet, $amount): Transaction {
            $lockedWallet = Wallet::whereKey($wallet->id)->lockForUpdate()->first();
            if (! $lockedWallet) {
                throw new WalletNotFoundException;
            }

            $idempotencyKey = self::idempotencyKey($lockedWallet);

            $existing = Transaction::where('initiated_by', $lockedWallet->user_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            $currentBalance = number_format((float) $lockedWallet->balance, 2, '.', '');
            $newBalance = bcadd($currentBalance, $amount, 2);
            $lockedWallet->balance = $newBalance;
            $lockedWallet->save();

            $sourceLabel = (string) config('transactions.signup_bonus.source_label', 'bKash');

            return Transaction::create([
                'user_id' => $lockedWallet->user_id,
                'sender_id' => null,
                'recipient_id' => $lockedWallet->user_id,
                'agent_id' => null,
                'initiated_by' => $lockedWallet->user_id,
                'type' => self::TYPE,
                'amount' => $amount,
                'system_fee_amount' => '0.00',
                'system_fee_rate' => '0.0000',
                'agent_commission_amount' => '0.00',
                'agent_commission_rate' => '0.0000',
                'currency' => $lockedWallet->currency ?? 'BDT',
                'description' => "Signup bonus from {$sourceLabel}",
                'status' => 'COMPLETED',
                'idempotency_key' => $idempotencyKey,
                'sender_wallet_balance_after' => null,
                'recipient_wallet_balance_after' => $newBalance,
                'agent_wallet_balance_after' => null,
                'sender_wallet_id' => null,
                'recipient_wallet_id' => $lockedWallet->id,
                'agent_wallet_id' => null,
                'meta' => [
                    'source' => 'SYSTEM',
                    'source_label' => $sourceLabel,
                    'wallet_kind' => $lockedWallet->kind,
                ],
            ]);
        });
    }
}
