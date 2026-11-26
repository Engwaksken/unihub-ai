<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Builds the sidebar from config('cms.navigation'). An item is shown only when
 * its feature flag is on, its route exists and the user holds the matching
 * capability, so modules that are not built yet never appear as dead links.
 */
final class Navigation
{
    /**
     * @return list<array{key: string, label: string, url: string, active: bool, icon: string}>
     */
    public static function for(User $user): array
    {
        $items = [];

        foreach (config('cms.navigation', []) as $item) {
            if (isset($item['feature']) && ! Features::enabled($item['feature'])) {
                continue;
            }

            $route = null;
            foreach ($item['routes'] as $capability => $candidate) {
                if (Route::has($candidate) && Gate::forUser($user)->allows($capability)) {
                    $route = $candidate;
                    break;
                }
            }

            if ($route === null) {
                continue;
            }

            $items[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'url' => route($route),
                'active' => request()->routeIs(...$item['active']),
                'icon' => $item['icon'] ?? 'dot',
            ];
        }

        return $items;
    }
}
