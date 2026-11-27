<?php

namespace App\Services;

use App\Models\User;
use App\Support\Audit;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * CMS-only settings stored in the CMS database (branding name, display time
 * zone, alert notification recipients, appearance). Changes are audited.
 */
class CmsSettings
{
    public const KEYS = [
        'brand_name', 'display_timezone', 'notification_recipients',
        'primary_color', 'accent_color', 'font', 'density', 'logo_path', 'favicon_path',
    ];

    public const DEFAULT_PRIMARY = '#660033';

    public const DEFAULT_ACCENT = '#d4af37';

    /** Font choices: Poppins is bundled; the others are system stacks (no external fonts, CSP font-src 'self'). */
    public const FONTS = [
        'poppins' => ['label' => 'Poppins (default)', 'stack' => '"Poppins", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif'],
        'system' => ['label' => 'System sans-serif', 'stack' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'],
        'humanist' => ['label' => 'Humanist (Segoe UI / Candara)', 'stack' => '"Segoe UI", Candara, "Trebuchet MS", Tahoma, sans-serif'],
        'serif' => ['label' => 'Serif (Georgia)', 'stack' => 'Georgia, Cambria, "Times New Roman", serif'],
    ];

    public const DENSITIES = ['comfortable' => 'Comfortable', 'compact' => 'Compact'];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'brand_name' => (string) config('app.name', 'Unihub AI'),
            'display_timezone' => (string) config('app.display_timezone', 'Africa/Kampala'),
            'notification_recipients' => [],
            'primary_color' => self::DEFAULT_PRIMARY,
            'accent_color' => self::DEFAULT_ACCENT,
            'font' => 'poppins',
            'density' => 'comfortable',
            'logo_path' => null,
            'favicon_path' => null,
        ];
    }

    /**
     * CSS custom properties for the chosen appearance, or '' when everything is default.
     * Values are validated hex colours and a fixed font stack, so they are safe to inline.
     */
    public function themeCss(): string
    {
        $primary = self::hex($this->get('primary_color')) ?? self::DEFAULT_PRIMARY;
        $accent = self::hex($this->get('accent_color')) ?? self::DEFAULT_ACCENT;
        $font = self::FONTS[(string) $this->get('font')]['stack'] ?? null;
        $vars = [];
        if ($primary !== self::DEFAULT_PRIMARY) {
            $vars['--burgundy'] = $primary;
            $vars['--burgundy-700'] = self::mix($primary, '#000000', .25);
            $vars['--burgundy-50'] = self::mix($primary, '#ffffff', .93);
            $vars['--focus'] = $primary;
        }
        if ($accent !== self::DEFAULT_ACCENT) {
            $vars['--gold'] = $accent;
            $vars['--gold-200'] = self::mix($accent, '#ffffff', .6);
        }
        if ($font !== null && $this->get('font') !== 'poppins') {
            $vars['--font'] = $font;
        }
        if ($vars === []) {
            return '';
        }

        return ':root{'.implode(';', array_map(fn ($k, $v) => "{$k}:{$v}", array_keys($vars), $vars)).'}';
    }

    public function density(): string
    {
        return array_key_exists((string) $this->get('density'), self::DENSITIES) ? (string) $this->get('density') : 'comfortable';
    }

    public function hasLogo(): bool
    {
        return is_string($this->get('logo_path')) && $this->get('logo_path') !== '';
    }

    public function hasFavicon(): bool
    {
        return is_string($this->get('favicon_path')) && $this->get('favicon_path') !== '';
    }

    /** Cache-busting version for /branding/* URLs. */
    public function assetVersion(string $key): string
    {
        return substr(hash('sha256', (string) $this->get($key)), 0, 10);
    }

    public static function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }

    /** WCAG contrast ratio between two #rrggbb colours. */
    public static function contrast(string $a, string $b): float
    {
        $lum = function (string $hex): float {
            $c = array_map(fn ($i) => hexdec(substr($hex, $i, 2)) / 255, [1, 3, 5]);
            $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$l1, $l2] = [$lum($a), $lum($b)];

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** Linear mix of two #rrggbb colours; $t = share of $with. */
    public static function mix(string $hex, string $with, float $t): string
    {
        $out = '#';
        foreach ([1, 3, 5] as $i) {
            $v = (int) round(hexdec(substr($hex, $i, 2)) * (1 - $t) + hexdec(substr($with, $i, 2)) * $t);
            $out .= str_pad(dechex(max(0, min(255, $v))), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $values = $this->defaults();
        try {
            if (Schema::hasTable('cms_settings')) {
                foreach (DB::table('cms_settings')->whereIn('key', self::KEYS)->get() as $row) {
                    $values[$row->key] = json_decode((string) $row->value, true);
                }
            }
        } catch (Throwable) {
            // Database not available yet (e.g. during install): use defaults.
        }

        return $this->values = $values;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function brandName(): string
    {
        $name = trim((string) $this->get('brand_name'));

        return $name !== '' ? $name : (string) config('app.name');
    }

    public function displayTimezone(): string
    {
        $zone = (string) $this->get('display_timezone');

        return in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : (string) config('app.display_timezone', 'Africa/Kampala');
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $list = $this->get('notification_recipients');

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /**
     * @param  array<string, mixed>  $changes  validated values for keys in KEYS
     */
    public function update(array $changes, User $actor): void
    {
        $before = $this->all();
        $changed = [];

        foreach ($changes as $key => $value) {
            if (! in_array($key, self::KEYS, true) || ($before[$key] ?? null) === $value) {
                continue;
            }
            DB::table('cms_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_by' => $actor->getKey(), 'updated_at' => now()],
            );
            $changed[$key] = ['before' => $before[$key] ?? null, 'after' => $value];
        }

        $this->values = null;

        if ($changed !== []) {
            Audit::record('settings.cms_updated', actor: $actor, metadata: ['changes' => $changed]);
        }
    }

    public function flush(): void
    {
        $this->values = null;
    }
}
