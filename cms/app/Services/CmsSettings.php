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
 * zone, alert notification recipients). Changes are audited.
 */
class CmsSettings
{
    public const KEYS = ['brand_name', 'display_timezone', 'notification_recipients'];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'brand_name' => (string) config('app.name', 'Unihub AI'),
            'display_timezone' => (string) config('app.display_timezone', 'Africa/Kampala'),
            'notification_recipients' => [],
        ];
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
