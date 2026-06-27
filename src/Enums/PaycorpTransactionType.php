<?php

namespace Hansajith18\LaravelPaycorp\Enums;

enum PaycorpTransactionType: string
{
    case PURCHASE = 'PURCHASE';
    case AUTHORISATION = 'AUTHORISATION';
    case TOKEN = 'TOKEN';
    case REFUND = 'REFUND';
    case ORPHANED_REFUND = 'ORPHANED_REFUND';
}
