<?php

declare(strict_types=1);

namespace Proxmox\Exceptions;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

class GuestPasswordResetException extends RequestException implements ProxmoxException
{
    protected function prepareMessage(Response $response): string
    {
        return "Guest password reset failed with HTTP status {$response->status()}.";
    }
}
