<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVersion;
use Illuminate\Database\Seeder;

/**
 * Synthetic staging/dev fixtures only (PRD §09: no production data).
 * Registers the pilot shape: one organization, the My Digital Diary staging
 * project, the Qwen2.5 1.5B model and a published `daily_plan` task.
 * The model is marked installed/enabled only when Ollama reports it
 * (run `POST /internal/v1/models/refresh`, then enable with a licence).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstOrCreate(['name' => 'Unihub Solutions Uganda'], ['status' => 'active']);

        $model = AiModel::firstOrCreate(['tag' => 'qwen2.5:1.5b'], [
            'licence' => 'Apache-2.0',
            'installed' => app()->environment('local'),
            'enabled' => app()->environment('local'),
        ]);

        $task = Task::firstOrCreate(['key' => 'daily_plan'], ['description' => 'Turn a short note into a time-blocked plan for the day.']);
        if (! TaskVersion::where('task_id', $task->id)->exists()) {
            $v = TaskVersion::create([
                'task_id' => $task->id,
                'version' => 1,
                'system_template' => "You are a helpful study and productivity assistant for a personal diary app.\n"
                    ."Create a short, realistic plan for the user's day as a bulleted list of time blocks.\n"
                    .'Keep it under 150 words. Do not give medical, legal or financial advice. Reply in the language the user wrote in.',
                'output_cap' => 220,
                'output_format' => 'text',
                'model_id' => $model->id,
                'created_by' => 'system:seeder',
            ]);
            $v->published_at = now();
            $v->save();
        }

        $project = Project::firstOrCreate(['slug' => 'my-digital-diary-staging'], [
            'organization_id' => $org->id,
            'name' => 'My Digital Diary (staging)',
            'owner_ref' => 'Pilot project owner',
            'environment' => 'staging',
            'status' => 'active',
            'daily_limit' => 100,
            'rpm_limit' => 5,
            'queue_limit' => 2,
        ]);
        $project->tasks()->syncWithoutDetaching([$task->id]);
    }
}
