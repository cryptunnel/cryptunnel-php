<?php

declare(strict_types=1);

namespace Cryptunnel\Exception;

/** waitForPayment gave up before the payment reached a terminal status. */
class PaymentTimeoutException extends CryptunnelException
{
}
