<?php

declare(strict_types=1);

namespace LiteStrip\Config;

/**
 * Defines which HTML attributes to preserve during DOM processing.
 *
 * All attributes not listed here are stripped. Safety transforms convert
 * potentially dangerous attributes (e.g. iframe src) to data-* equivalents
 * so AI can read them without triggering browser behavior.
 */
final class AllowedAttributes
{
    /** @var array<string, list<string>> Allowed attributes per tag name */
    private const array TAG_ATTRIBUTES = [
        'a'        => ['href', 'rel'],
        'img'      => ['src', 'alt'],
        'video'    => ['src'],
        'audio'    => ['src'],
        'source'   => ['src'],
        'time'     => ['datetime'],
        'td'       => ['colspan', 'rowspan'],
        'th'       => ['colspan', 'rowspan'],
        'input'    => ['type', 'name', 'value', 'placeholder'],
        'button'   => ['type'],
        'ol'       => ['type'],
        'select'   => ['name'],
        'textarea' => ['name', 'placeholder'],
        'option'   => ['value'],
        'details'  => ['open'],
        'meta'     => ['content', 'charset'],
        'link'     => ['rel', 'href'],
    ];

    /** @var list<string> Attributes allowed on any element */
    private const array GLOBAL_ATTRIBUTES = ['lang', 'dir'];

    /** @var array<string, array<string, string>> Safety transforms: tag => [original => data-prefixed] */
    private const array SAFETY_TRANSFORMS = [
        'iframe' => ['src' => 'data-src'],
        'form'   => ['action' => 'data-action', 'method' => 'data-method'],
    ];

    /**
     * Returns the list of allowed attribute names for the given tag.
     *
     * @param string $tag Lowercase tag name
     * @return list<string> Allowed attribute names (tag-specific + global)
     */
    public static function forTag(string $tag): array
    {
        return array_merge(
            self::TAG_ATTRIBUTES[$tag] ?? [],
            self::GLOBAL_ATTRIBUTES
        );
    }

    /**
     * Returns safety transform mappings for the given tag.
     *
     * @param string $tag Lowercase tag name
     * @return array<string, string> Map of original attribute name => data-prefixed name
     */
    public static function safetyTransforms(string $tag): array
    {
        return self::SAFETY_TRANSFORMS[$tag] ?? [];
    }
}
