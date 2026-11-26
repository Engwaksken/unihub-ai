<?php

namespace App\Support;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

final class Features
{
    public static function enabled(string $feature): bool
    {
        return (bool) config("cms.features.{$feature}", false);
    }

    /**
     * Project detail tabs that are switched on, whose route exists and that
     * the current user may open for the given project.
     *
     * @return list<array{key: string, label: string, route: string}>
     */
    public static function projectTabs(?string $projectUuid = null): array
    {
        $tabs = [];
        foreach (config('cms.project_tabs', []) as $tab) {
            $enabled = ! isset($tab['feature']) || self::enabled($tab['feature']);
            $allowed = $projectUuid === null || ! isset($tab['capability']) || Gate::allows($tab['capability'], strtolower($projectUuid));
            if ($enabled && $allowed && Route::has($tab['route'])) {
                $tabs[] = $tab;
            }
        }

        return $tabs;
    }
}
