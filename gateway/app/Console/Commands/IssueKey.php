<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\AuditLogger;
use App\Services\KeyService;
use Illuminate\Console\Command;

/**
 * Break-glass / bootstrap key issuance on the gateway host (e.g. pilot staging before
 * the CMS is deployed). Normal issuance goes through the CMS → management API.
 */
class IssueKey extends Command
{
    protected $signature = 'gateway:key:issue {slug : Project slug} {--label=cli} {--days= : Expire after N days}';

    protected $description = 'Issue a project API key from the CLI (shown once, audited)';

    public function handle(KeyService $keys, AuditLogger $audit): int
    {
        $project = Project::where('slug', $this->argument('slug'))->first();
        if ($project === null || ! $project->isActive()) {
            $this->error('Active project not found.');

            return self::FAILURE;
        }
        $expires = $this->option('days') ? now()->addDays((int) $this->option('days')) : null;
        [$key, $secret] = $keys->issue($project, 'system:cli', (string) $this->option('label'), $expires);
        $audit->record('system:cli', 'key.issued', 'api_key', $key->id, $project->id, ['prefix' => $key->prefix, 'via' => 'cli']);

        $this->warn('API key (shown ONCE — store it in the project backend secret store, never in a mobile build):');
        $this->line($secret);

        return self::SUCCESS;
    }
}
