<?php

namespace App\Services;

use InvalidArgumentException;

final class XUIServiceFactory
{
    public static function make(string $panelType, string $host, string $username, string $password): XUIServiceContract
    {
        return match ($panelType) {
            'sanaei', 'xui' => new SanaeiXUIService($host, $username, $password),
            'txui' => new AlirezaXUIService($host, $username, $password),
            default => throw new InvalidArgumentException('نوع پنل X-UI معتبر نیست.'),
        };
    }
}
