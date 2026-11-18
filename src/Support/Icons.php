<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Inline SVG icons (stroke style, 24x24 grid, currentColor). Markup is static and trusted;
 * icons are decorative (aria-hidden) and always sit next to a visible text label.
 */
final class Icons
{
    private const PATHS = [
        'inbox' => '<path d="M3 13h5l1.5 3h5L16 13h5"/><path d="M5.5 5h13L21 13v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-5z"/>',
        'contact' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/><path d="M8.5 11h7M8.5 14h4"/>',
        'quote' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
        'sample' => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'other' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M21 20H3"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'flag' => '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    ];

    public static function svg(string $name, int $size = 20, string $class = 'icon'): string
    {
        $paths = self::PATHS[$name] ?? self::PATHS['other'];
        return '<svg class="' . e($class) . '" viewBox="0 0 24 24" width="' . $size . '" height="' . $size
            . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }

    /** Coloured type badge with icon, e.g. [chat icon] Contact. */
    public static function typeBadge(string $type, string $label): string
    {
        $type = in_array($type, ['contact', 'quote', 'sample', 'other'], true) ? $type : 'other';
        return '<span class="type type-' . $type . '">' . self::svg($type, 14) . e($label) . '</span>';
    }
}
