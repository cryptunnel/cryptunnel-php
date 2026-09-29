<?php

declare(strict_types=1);

namespace Cryptunnel\Exception;

/** 401: wrong merchant id, wrong or rotated key, or a suspended merchant. */
class AuthenticationException extends CryptunnelException
{
}
