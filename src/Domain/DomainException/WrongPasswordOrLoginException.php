<?php
declare(strict_types=1);

namespace App\Domain\DomainException;

use Throwable;

class WrongPasswordOrLoginException extends DomainException {
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
