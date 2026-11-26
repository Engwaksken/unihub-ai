<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags
    |--------------------------------------------------------------------------
    | Every module is built and on by default. Turning a flag off hides the
    | menu item / project tab and makes its routes return 404.
    */
    'features' => [
        'overview' => (bool) env('CMS_FEATURE_OVERVIEW', true),
        'api_keys' => (bool) env('CMS_FEATURE_API_KEYS', true),
        'models_tasks' => (bool) env('CMS_FEATURE_MODELS_TASKS', true),
        'jobs' => (bool) env('CMS_FEATURE_JOBS', true),
        'usage' => (bool) env('CMS_FEATURE_USAGE', true),
        'health' => (bool) env('CMS_FEATURE_HEALTH', true),
        'settings' => (bool) env('CMS_FEATURE_SETTINGS', true),
        'project_keys_tab' => (bool) env('CMS_FEATURE_PROJECT_KEYS_TAB', true),
        'project_jobs_tab' => (bool) env('CMS_FEATURE_PROJECT_JOBS_TAB', true),
        'project_usage_tab' => (bool) env('CMS_FEATURE_PROJECT_USAGE_TAB', true),
    ],

    /*
    | Sidebar. `routes` maps capability => route name; the first one the user
    | holds (and whose route exists) is used for the link.
    */
    'navigation' => [
        ['key' => 'overview', 'label' => 'Overview', 'icon' => 'home', 'feature' => 'overview',
            'routes' => ['overview.view' => 'overview'], 'active' => ['overview']],
        ['key' => 'projects', 'label' => 'Projects', 'icon' => 'folder',
            'routes' => ['project.view' => 'projects.index'], 'active' => ['projects.*']],
        ['key' => 'organizations', 'label' => 'Organizations', 'icon' => 'building',
            'routes' => ['organization.view' => 'organizations.index'], 'active' => ['organizations.*']],
        ['key' => 'api_keys', 'label' => 'API keys', 'icon' => 'key', 'feature' => 'api_keys',
            'routes' => ['key.view' => 'keys.index'], 'active' => ['keys.*']],
        ['key' => 'models_tasks', 'label' => 'Models & tasks', 'icon' => 'cpu', 'feature' => 'models_tasks',
            'routes' => ['model.view' => 'models.index'], 'active' => ['models.*', 'tasks.*']],
        ['key' => 'jobs', 'label' => 'Jobs & queue', 'icon' => 'queue', 'feature' => 'jobs',
            'routes' => ['job.view' => 'jobs.index'], 'active' => ['jobs.*']],
        ['key' => 'usage', 'label' => 'Usage & reports', 'icon' => 'chart', 'feature' => 'usage',
            'routes' => ['usage.view' => 'usage.index'], 'active' => ['usage.*']],
        ['key' => 'health', 'label' => 'Server & alerts', 'icon' => 'server', 'feature' => 'health',
            'routes' => ['health.view' => 'health.index'], 'active' => ['health.*', 'alerts.*']],
        ['key' => 'users', 'label' => 'Users & audit', 'icon' => 'users',
            'routes' => ['staff.manage' => 'users.index', 'audit.view' => 'audit.index'], 'active' => ['users.*', 'audit.*']],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'cog', 'feature' => 'settings',
            'routes' => ['settings.cms' => 'settings.index'], 'active' => ['settings.*']],
    ],

    /*
    | Project detail tabs, shown when the flag is on and the user holds the
    | capability on that project.
    */
    'project_tabs' => [
        ['key' => 'keys', 'label' => 'API keys', 'route' => 'projects.keys', 'feature' => 'project_keys_tab', 'capability' => 'key.view'],
        ['key' => 'jobs', 'label' => 'Jobs', 'route' => 'projects.jobs', 'feature' => 'project_jobs_tab', 'capability' => 'job.view'],
        ['key' => 'usage', 'label' => 'Usage', 'route' => 'projects.usage', 'feature' => 'project_usage_tab', 'capability' => 'usage.view'],
        ['key' => 'settings', 'label' => 'Settings', 'route' => 'projects.settings', 'capability' => 'project.view'],
    ],

    /*
    | Lifetime of staff invitation / set-password links.
    */
    'invitation_ttl_hours' => 24,

    /*
    | Local seeding only (SyntheticStaffSeeder): gateway staging project UUIDs.
    */
    'seed_project_uuids' => env('SEED_PROJECT_UUIDS', ''),

];
