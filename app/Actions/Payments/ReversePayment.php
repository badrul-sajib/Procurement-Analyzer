<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use Illuminate\Support\Facades\DB;

/** A reversed payment and its allocations stop counting; the records stay for audit. */
class ReversePayment
{
    public function handle(Payment $payment, string $reason, int $userId): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $userId) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === PaymentStatus::Reversed) {
                throw new BusinessRuleException('invalid_status', 'This payment is already reversed.');
            }
            $payment->forceFill(['status' => PaymentStatus::Reversed, 'reversed_by' => $userId, 'reversed_at' => now(), 'reversal_reason' => $reason])->save();
            AuditLogger::log('payment.reversed', 'payments', $payment->id, "Reversed {$payment->payment_reference}: {$reason}", ['status' => 'active'], ['status' => 'reversed', 'amount' => $payment->amount]);
            DataVersion::bump();

            return $payment;
        });
    }
}
