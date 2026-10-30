<?php

declare(strict_types=1);

/**
 * View helpers shared by every module: IST time formatting, the semantic
 * icon/colour map (frontend/theme → public/assets/ui-map.json), Vite assets.
 */

use Modules\Core\Libraries\Clock;

if (! function_exists('ist')) {
    /** Format a UTC DB value in Asia/Kolkata. */
    function ist(?string $utc, string $format = 'd M Y, H:i'): string
    {
        return Clock::ist($utc, $format);
    }
}

if (! function_exists('ist_input')) {
    /** UTC DB value → value for <input type="datetime-local"> in IST. */
    function ist_input(?string $utc): string
    {
        return $utc ? Clock::ist($utc, 'Y-m-d\TH:i') : '';
    }
}

if (! function_exists('ui_map')) {
    /** @return array{icons: array<string,string>, semantic: array<string, array<string, array<string,string>>>} */
    function ui_map(): array
    {
        static $map = null;
        if ($map === null) {
            $file = FCPATH . 'assets/ui-map.json';
            $map  = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
            $map += ['icons' => [], 'semantic' => []];
        }

        return $map;
    }
}

if (! function_exists('icon')) {
    /**
     * SVG sprite icon by semantic name (e.g. 'phase.checkin_open', 'action.print')
     * or raw Tabler icon id. Decorative unless $label is given.
     */
    function icon(string $name, string $class = '', ?string $label = null): string
    {
        $id   = ui_map()['icons'][$name] ?? $name;
        $aria = $label === null ? 'aria-hidden="true"' : 'role="img" aria-label="' . esc($label, 'attr') . '"';

        return '<svg class="icon ' . esc($class, 'attr') . '" ' . $aria . '><use href="' . base_url('assets/icons.svg') . '#' . esc($id, 'attr') . '"></use></svg>';
    }
}

if (! function_exists('semantic')) {
    /** @return array{color: string, icon: string, label: string} */
    function semantic(string $group, string $value): array
    {
        $m = ui_map()['semantic'][$group][$value] ?? null;

        return [
            'color' => $m['color'] ?? 'secondary',
            'icon'  => $m['icon'] ?? 'circle',
            'label' => $m['label'] ?? ucfirst(str_replace('_', ' ', $value)),
        ];
    }
}

if (! function_exists('status_badge')) {
    /** Badge with colour + icon + text (never colour alone). */
    function status_badge(string $group, string $value, ?string $text = null, string $extra = ''): string
    {
        $s = semantic($group, $value);

        return '<span class="badge bg-' . esc($s['color'], 'attr') . '-lt ' . esc($extra, 'attr') . '">'
            . icon($s['icon'], 'icon-inline me-1') . esc($text ?? $s['label']) . '</span>';
    }
}

if (! function_exists('vite_tags')) {
    /**
     * <link>/<script> tags for a Vite entry (e.g. 'frontend/entries/admin.js'),
     * including CSS of imported chunks and modulepreload hints.
     */
    function vite_tags(string $entry): string
    {
        static $manifest = null;
        if ($manifest === null) {
            $file     = FCPATH . 'assets/.vite/manifest.json';
            $manifest = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        }
        if (! isset($manifest[$entry])) {
            return '<!-- vite entry missing: ' . esc($entry) . ' (run npm run build) -->';
        }
        $css  = [];
        $pre  = [];
        $seen = [];
        $walk = static function (string $key) use (&$walk, &$css, &$pre, &$seen, $manifest): void {
            if (isset($seen[$key]) || ! isset($manifest[$key])) {
                return;
            }
            $seen[$key] = true;
            foreach ($manifest[$key]['css'] ?? [] as $c) {
                $css[$c] = true;
            }
            foreach ($manifest[$key]['imports'] ?? [] as $imp) {
                $pre[$manifest[$imp]['file']] = true;
                $walk($imp);
            }
        };
        $walk($entry);
        $out = '';
        foreach (array_keys($css) as $c) {
            $out .= '<link rel="stylesheet" href="' . base_url('assets/' . $c) . '">' . "\n";
        }
        foreach (array_keys($pre) as $p) {
            $out .= '<link rel="modulepreload" href="' . base_url('assets/' . $p) . '">' . "\n";
        }

        return $out . '<script type="module" src="' . base_url('assets/' . $manifest[$entry]['file']) . '"></script>' . "\n";
    }
}

if (! function_exists('boot_json')) {
    /** Initial data for a Vue screen, safe inside <script type="application/json">. */
    function boot_json(array $data, string $id = 'boot'): string
    {
        return '<script type="application/json" id="' . esc($id, 'attr') . '">'
            . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>';
    }
}

if (! function_exists('nl2br_esc')) {
    /** Plain text with line breaks, escaped (no raw HTML ever). */
    function nl2br_esc(?string $text): string
    {
        return nl2br(esc((string) $text), false);
    }
}

if (! function_exists('field_error')) {
    function field_error(array $errors, string $field): string
    {
        return isset($errors[$field]) ? '<div class="invalid-feedback d-block">' . esc($errors[$field]) . '</div>' : '';
    }
}

if (! function_exists('is_invalid')) {
    function is_invalid(array $errors, string $field): string
    {
        return isset($errors[$field]) ? ' is-invalid' : '';
    }
}
