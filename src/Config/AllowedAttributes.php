<?php

declare(strict_types=1);

namespace LiteStrip\Config;

final class AllowedAttributes
{
    /** @var array<string, list<string>> tag => allowed attributes */
    private const TAG_ATTRIBUTES = [
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

    /** @var list<string> attributes allowed on any element */
    private const GLOBAL_ATTRIBUTES = ['lang', 'dir'];

    /** @var array<string, array<string, string>> tag => [original => data-prefixed] */
    private const SAFETY_TRANSFORMS = [
        'iframe' => ['src' => 'data-src'],
        'form'   => ['action' => 'data-action', 'method' => 'data-method'],
    ];

    /**
     * @return list<string> tag で許可される属性名リスト
     */
    public static function forTag(string $tag): array
    {
        return array_merge(
            self::TAG_ATTRIBUTES[$tag] ?? [],
            self::GLOBAL_ATTRIBUTES
        );
    }

    /**
     * @return array<string, string> 安全化変換マップ (original => data-prefixed)
     */
    public static function safetyTransforms(string $tag): array
    {
        return self::SAFETY_TRANSFORMS[$tag] ?? [];
    }

}
