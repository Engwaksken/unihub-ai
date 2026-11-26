<x-layouts.app :title="$project->name.' · Settings'">
    <x-project-header :project="$project" />

    @php($canEditAny = $can['metadata'] || $can['quotas'] || $can['allowlist'])

    <section class="card" aria-labelledby="settings-heading">
        <h2 id="settings-heading">Project settings</h2>
        @unless ($canEditAny)
            <p class="muted">You can view these settings but not change them.</p>
        @endunless

        <form method="POST" action="{{ route('projects.update', $project->id) }}" class="form" x-data="{ busy: false }" x-on:submit="busy = true">
            @csrf
            @method('PATCH')
            <x-idempotency-key />
            <x-form-errors />

            <fieldset class="fieldset" @disabled(! $can['metadata'])>
                <legend>Details</legend>
                <div class="form-grid">
                    <div class="field">
                        <label for="name">Project name</label>
                        <input id="name" name="name" type="text" maxlength="120" required value="{{ old('name', $project->name) }}">
                        @error('name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="environment">Environment</label>
                        <select id="environment" name="environment">
                            @foreach (\App\Gateway\Data\Project::ENVIRONMENTS as $env)
                                <option value="{{ $env }}" @selected(old('environment', $project->environment) === $env)>{{ ucfirst($env) }}</option>
                            @endforeach
                        </select>
                        @error('environment') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="owner_ref">Accountable owner</label>
                        <input id="owner_ref" name="owner_ref" type="text" maxlength="120" value="{{ old('owner_ref', $project->ownerRef) }}">
                        @error('owner_ref') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="contact_email">Contact e-mail</label>
                        <input id="contact_email" name="contact_email" type="email" maxlength="190" value="{{ old('contact_email', $project->contactEmail) }}">
                        @error('contact_email') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>

            <fieldset class="fieldset" @disabled(! $can['quotas'])>
                <legend>Quotas</legend>
                @unless ($can['quotas'])
                    <p class="field-help">Only an owner can change quotas and queue limits.</p>
                @endunless
                <div class="form-grid form-grid-3">
                    <div class="field">
                        <label for="daily_limit">Requests per day</label>
                        <input id="daily_limit" name="daily_limit" type="number" min="0" step="1" value="{{ old('daily_limit', $project->dailyLimit) }}">
                        @error('daily_limit') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="rpm_limit">Requests per minute</label>
                        <input id="rpm_limit" name="rpm_limit" type="number" min="0" step="1" value="{{ old('rpm_limit', $project->rpmLimit) }}">
                        @error('rpm_limit') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="queue_limit">Queued jobs (max 8)</label>
                        <input id="queue_limit" name="queue_limit" type="number" min="0" max="8" step="1" value="{{ old('queue_limit', $project->queueLimit) }}">
                        @error('queue_limit') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>

            <fieldset class="fieldset" @disabled(! $can['allowlist'])>
                <legend>Task allowlist</legend>
                <div class="field">
                    <label for="allowed_tasks">Allowed tasks</label>
                    <textarea id="allowed_tasks" name="allowed_tasks" rows="2" aria-describedby="tasks-help">{{ old('allowed_tasks', implode(', ', $project->allowedTasks)) }}</textarea>
                    <p id="tasks-help" class="field-help">Task keys separated by commas or new lines. Jobs for other tasks are rejected.</p>
                    @error('allowed_tasks') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </fieldset>

            @if ($canEditAny)
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" x-bind:disabled="busy">Save settings</button>
                </div>
            @endif
        </form>

        <dl class="details details-inline">
            <div><dt>Project ID</dt><dd><code>{{ $project->id }}</code></dd></div>
            <div><dt>Created</dt><dd><x-time :value="$project->createdAt" /></dd></div>
            <div><dt>Last updated</dt><dd><x-time :value="$project->updatedAt" /></dd></div>
        </dl>
    </section>

    @if ($can['status'])
        <section class="card" aria-labelledby="status-heading">
            <h2 id="status-heading">Status</h2>
            <p>Current status: <x-status-badge :status="$project->status" /></p>
            <p class="field-help">Disabled projects keep their keys and data but the gateway refuses new jobs. Archived projects are retired.</p>
            <div class="actions-row">
                @if ($project->status !== 'active')
                    <x-confirm-dialog :title="'Enable '.$project->name.'?'" :action="route('projects.status', $project->id)" method="PATCH"
                                      trigger-label="Enable project" trigger-class="btn btn-secondary" confirm-label="Enable" confirm-class="btn btn-primary">
                        <x-slot:fields><input type="hidden" name="status" value="active"></x-slot:fields>
                        The gateway will accept new jobs for this project again.
                    </x-confirm-dialog>
                @endif
                @if ($project->status !== 'disabled')
                    <x-confirm-dialog :title="'Disable '.$project->name.'?'" :action="route('projects.status', $project->id)" method="PATCH"
                                      trigger-label="Disable project" trigger-class="btn btn-secondary" confirm-label="Disable project">
                        <x-slot:fields><input type="hidden" name="status" value="disabled"></x-slot:fields>
                        New jobs from this project will be rejected until it is enabled again.
                    </x-confirm-dialog>
                @endif
                @if ($project->status !== 'archived')
                    <x-confirm-dialog :title="'Archive '.$project->name.'?'" :action="route('projects.status', $project->id)" method="PATCH"
                                      trigger-label="Archive project" trigger-class="btn btn-danger" confirm-label="Archive project">
                        <x-slot:fields><input type="hidden" name="status" value="archived"></x-slot:fields>
                        The project is retired and stops accepting jobs. Its history stays available for audit and reports.
                    </x-confirm-dialog>
                @endif
            </div>
        </section>
    @endif
</x-layouts.app>
