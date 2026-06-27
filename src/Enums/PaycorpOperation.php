<?php

namespace Hansajith18\LaravelPaycorp\Enums;

enum PaycorpOperation: string
{
    case PAYMENT_INIT = 'PAYMENT_INIT';
    case PAYMENT_COMPLETE = 'PAYMENT_COMPLETE';
    case PAYMENT_REAL_TIME = 'PAYMENT_REAL_TIME';
}
