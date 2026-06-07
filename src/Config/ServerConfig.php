<?php

declare(strict_types=1);

namespace LiteStrip\Config;

final class ServerConfig
{
    public const VERSION = '1.0.0';
    public const DEFAULT_PORT = 8080;

    public const DEFAULT_FORMAT = 'json';
    public const ALLOWED_FORMATS = ['html', 'json', 'markdown'];

    public const DEFAULT_TIMEOUT = 15;
    public const MAX_TIMEOUT = 30;

    public const DEFAULT_MAX_APIS = 5;
    public const MAX_MAX_APIS = 10;

    public const API_ENDPOINT_TIMEOUT = 10;

    public const ONE_MEGABYTE_IN_BYTES = 1024 * 1024;
    public const MAX_HTML_SIZE = 2 * self::ONE_MEGABYTE_IN_BYTES;
    public const MAX_API_RESPONSE_SIZE = self::ONE_MEGABYTE_IN_BYTES;

    public const MAX_URL_LENGTH = 2048;
    public const MAX_REDIRECTS = 3;

    public const USER_AGENT = 'LiteStrip/' . self::VERSION . ' (+https://github.com/space-musicacct/lite-strip)';

    public const ENABLE_SPA_DEFAULT = true;
}
