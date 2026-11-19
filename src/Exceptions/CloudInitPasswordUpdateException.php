<?php

declare(strict_types=1);

namespace Proxmox\Exceptions;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

class CloudInitPasswordUpdateException extends RequestException implements ProxmoxException
{
    protected function prepareMessage(Response $response): string
    {
        return "Cloud-Init password update failed with HTTP status {$response->status()}.";
    }
}
