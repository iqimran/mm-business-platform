<?php

namespace App\Modules\Restaurant\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case MobileBanking = 'mobile_banking';
    case Other = 'other';
}
