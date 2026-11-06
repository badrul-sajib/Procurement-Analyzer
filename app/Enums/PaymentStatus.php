<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Active = 'active';
    case Reversed = 'reversed';
}
