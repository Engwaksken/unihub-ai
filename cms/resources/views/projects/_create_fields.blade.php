{{-- Fields of the "new project" form, shared by projects/create and the modal on the projects list.
     Expects $organizations (active, list<Organization>), $canSetQuotas, $canSetAllowlist. --}}
<x-tabs name="new-project" aria-label="New project" :tabs="['basics' => 'Basics', 'contact' => 'Contact', 'limits' => 'Limits & tasks']"
        :fields="['basics' => ['organization_id', 'name', 'slug', 'environment'], 'contact' => ['owner_ref', 'contact_email'], 'limits' => ['daily_limit', 'rpm_limit', 'queue_limit', 'allowed_tasks']]">
    <x-tab-panel tabs="new-project" key="basics">
        <div class="form-grid">
            <div class="field">
                <label for="organization_id">Organization</label>
                <select id="organization_id" name="organization_id" required>
                    <option value="">Choose…</option>
                    @foreach ($organizations as $org)
                        <option value="{{ $org->id }}" @selected(old('organization_id') === $org->id)>{{ $org->name }}</option>
                    @endforeach
                </select>
                @error('organization_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="name">Project name</label>
                <input id="name" name="name" type="text" maxlength="120" required value="{{ old('name') }}">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="slug">Slug</label>
                <input id="slug" name="slug" type="text" maxlength="63" required pattern="[a-z0-9][a-z0-9\-]{2,62}" value="{{ old('slug') }}" aria-describedby="slug-help">
                <p id="slug-help" class="field-help">Lowercase letters, digits and hyphens, e.g. <code>my-digital-diary</code>. It cannot be changed later.</p>
                @error('slug') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="environment">Environment</label>
                <select id="environment" name="environment" required>
                    @foreach (\App\Gateway\Data\Project::ENVIRONMENTS as $env)
                        <option value="{{ $env }}" @selected(old('environment', 'staging') === $env)>{{ ucfirst($env) }}</option>
                    @endforeach
                </select>
                @error('environment') <p class="field-error">{{ $message }}</p> @enderror
            </div>

        </div>
    </x-tab-panel>
    <x-tab-panel tabs="new-project" key="contact">
        <div class="form-grid">
            <div class="field">
                <label for="owner_ref">Accountable owner <span class="optional">(optional)</span></label>
                <input id="owner_ref" name="owner_ref" type="text" maxlength="120" value="{{ old('owner_ref') }}" aria-describedby="owner-help">
                <p id="owner-help" class="field-help">Name or role of the person accountable for this project.</p>
                @error('owner_ref') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="contact_email">Contact e-mail <span class="optional">(optional)</span></label>
                <input id="contact_email" name="contact_email" type="email" maxlength="190" value="{{ old('contact_email') }}">
                @error('contact_email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
    </x-tab-panel>
    <x-tab-panel tabs="new-project" key="limits">

        @if ($canSetQuotas)
            <fieldset class="fieldset">
                <legend>Quotas</legend>
                <p class="field-help">Leave blank to use the gateway defaults (100 per day, 5 per minute, queue of 2).</p>
                <div class="form-grid form-grid-3">
                    <div class="field">
                        <label for="daily_limit">Requests per day</label>
                        <input id="daily_limit" name="daily_limit" type="number" min="0" step="1" value="{{ old('daily_limit') }}">
                        @error('daily_limit') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="rpm_limit">Requests per minute</label>
                        <input id="rpm_limit" name="rpm_limit" type="number" min="0" step="1" value="{{ old('rpm_limit') }}">
                        @error('rpm_limit') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="queue_limit">Queued jobs (max 8)</label>
                        <input id="queue_limit" name="queue_limit" type="number" min="0" max="8" step="1" value="{{ old('queue_limit') }}">
                        @error('queue_limit') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>
        @else
            <p class="field-help">Quotas start at the gateway defaults; only an owner can change them.</p>
        @endif

        @if ($canSetAllowlist)
            <div class="field">
                <label for="allowed_tasks">Allowed tasks <span class="optional">(optional)</span></label>
                <textarea id="allowed_tasks" name="allowed_tasks" rows="2" aria-describedby="tasks-help">{{ old('allowed_tasks') }}</textarea>
                <p id="tasks-help" class="field-help">Task keys separated by commas or new lines, e.g. <code>daily_plan</code>.</p>
                @error('allowed_tasks') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        @endif

    </x-tab-panel>
</x-tabs>
