<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class MailDeliveryException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('No fue posible enviar el correo. Verifica la configuración SMTP del servidor.', 0, $previous);
    }
}
