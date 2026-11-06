<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case MobileFinancialService = 'mobile_financial_service';
    case Cheque = 'cheque';
    case Other = 'other';
}
