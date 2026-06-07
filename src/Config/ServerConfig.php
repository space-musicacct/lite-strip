<?php

declare(strict_types=1);

namespace LiteStrip\Config;

/**
 * Application-wide configuration constants.
 */
final class ServerConfig
{
    /** @var string Application version */
    public const string VERSION = '1.0.0';

    /** @var int Default HTTP server port */
    public const int DEFAULT_PORT = 8080;

    /** @var string Default output format */
    public const string DEFAULT_FORMAT = 'json';

    /** @var list<string> Supported output formats */
    public const array ALLOWED_FORMATS = ['html', 'json', 'markdown'];

    /** @var int Default per-request timeout in seconds */
    public const int DEFAULT_TIMEOUT = 15;

    /** @var int Maximum allowed timeout in seconds */
    public const int MAX_TIMEOUT = 30;

    /** @var int Default number of API endpoints to follow */
    public const int DEFAULT_MAX_APIS = 5;

    /** @var int Maximum number of API endpoints to follow */
    public const int MAX_MAX_APIS = 10;

    /** @var int Timeout for each API endpoint request in seconds */
    public const int API_ENDPOINT_TIMEOUT = 10;

    /** @var int 1 MB in bytes */
    public const int ONE_MEGABYTE_IN_BYTES = 1024 * 1024;

    /** @var int Maximum HTML response size in bytes (2 MB) */
    public const int MAX_HTML_SIZE = 2 * self::ONE_MEGABYTE_IN_BYTES;

    /** @var int Maximum API response size per endpoint in bytes (1 MB) */
    public const int MAX_API_RESPONSE_SIZE = self::ONE_MEGABYTE_IN_BYTES;

    /** @var int Maximum URL length in characters */
    public const int MAX_URL_LENGTH = 2048;

    /** @var int Maximum number of HTTP redirects to follow */
    public const int MAX_REDIRECTS = 3;

    /** @var string User-Agent header sent with outbound requests */
    public const string USER_AGENT = 'LiteStrip/' . self::VERSION . ' (+https://github.com/space-musicacct/lite-strip)';

    /** @var bool Default value for SPA rendering mode */
    public const bool ENABLE_SPA_DEFAULT = true;
}
