<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Reversed = 'reversed';
}
