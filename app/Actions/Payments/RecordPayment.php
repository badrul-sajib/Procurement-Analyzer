<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use App\Support\ReferenceNumber;
use App\Support\Transactions;

class RecordPayment
{
    public function __construct(private AllocatePayment $allocate) {}

    public function handle(array $data, int $userId): Payment
    {
        return Transactions::critical(function () use ($data, $userId) {
            $payment = Payment::create([
                'vendor_id' => $data['vendor_id'],
                'payment_date' => $data['payment_date'],
                'amount' => $data['amount'],
                'method' => $data['method'],
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => PaymentStatus::Active,
                'created_by' => $userId,
            ]);
            ReferenceNumber::assign($payment, 'PAY', 'payment_reference');
            AuditLogger::log('payment.created', 'payments', $payment->id, "Recorded {$payment->payment_reference} of {$payment->amount}", null,
                array_intersect_key($data, array_flip(['vendor_id', 'payment_date', 'amount', 'method', 'transaction_reference'])));
            DataVersion::bump();

            if (! empty($data['allocations'])) {
                $this->allocate->handle($payment, $data['allocations'], $userId);
            }

            return $payment;
        });
    }
}
