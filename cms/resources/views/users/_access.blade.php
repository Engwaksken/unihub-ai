{{--
    Role checkboxes and the project membership table, shared by invite and edit.
    Expects: $roles (list<Role>), $selectedRoles (list<string>), $projects (list<Project>|null),
    $projectsError (?string), $currentMemberships (array uuid => role value).
    Optional: $idPrefix (unique per form when several are on one page), $useOld (default true:
    refill from old input; pass false for a modal that was not the one submitted).
--}}
@php
    $idPrefix ??= '';
    $useOld ??= true;
    $section ??= 'all'; // 'roles' | 'projects' | 'all' (tabbed forms include it once per tab)
@endphp
@if ($section !== 'projects')
<fieldset class="fieldset">
    <legend>Roles</legend>
    <div class="checkbox-list">
        @foreach ($roles as $role)
            <label class="checkbox">
                <input type="checkbox" name="roles[]" value="{{ $role->value }}" @checked(in_array($role->value, $selectedRoles, true))>
                <span>
                    <strong>{{ $role->label() }}</strong>
                    <span class="field-help">
                        @switch($role)
                            @case(\App\Enums\Role::Owner) Full control, including staff, quotas and policy settings. @break
                            @case(\App\Enums\Role::OpsAdmin) Runs the platform: projects, models, jobs and health; no staff management or key secrets. @break
                            @case(\App\Enums\Role::ProjectManager) Manages assigned projects: metadata and API keys. @break
                            @case(\App\Enums\Role::Auditor) Read-only access to assigned projects. @break
                        @endswitch
                    </span>
                </span>
            </label>
        @endforeach
    </div>
    @error('roles') <p class="field-error">{{ $message }}</p> @enderror
</fieldset>
@endif
@if ($section !== 'roles')
<fieldset class="fieldset">
    <legend>Project assignments</legend>
    <p class="field-help">Project managers and auditors only see the projects assigned here. Owners and ops admins see every project.</p>

    @if ($projectsError)
        <div class="alert alert-warning" role="status">{{ $projectsError }}</div>
    @endif

    @php
        $rows = [];
        foreach ($projects ?? [] as $p) {
            $rows[$p->id] = ['label' => $p->name, 'sub' => trim(($p->organizationName ?? '').' · '.$p->slug, ' ·')];
        }
        foreach ($currentMemberships as $uuid => $role) {
            $rows[$uuid] ??= ['label' => $projects === null ? 'Project '.$uuid : 'Unknown project', 'sub' => $uuid];
        }
    @endphp

    @if ($rows === [])
        <p class="muted">No projects are registered on the gateway yet.</p>
    @else
        <div class="table-wrap">
            <table class="table table-compact">
                <caption class="visually-hidden">Project assignments</caption>
                <thead>
                    <tr>
                        <th scope="col">Project</th>
                        <th scope="col">Assignment</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $uuid => $row)
                        @php($current = $useOld ? old('memberships.'.$uuid, $currentMemberships[$uuid] ?? 'none') : ($currentMemberships[$uuid] ?? 'none'))
                        <tr>
                            <th scope="row">
                                <label for="{{ $idPrefix }}m-{{ $uuid }}">{{ $row['label'] }}</label>
                                <span class="cell-sub">{{ $row['sub'] }}</span>
                            </th>
                            <td>
                                <select id="{{ $idPrefix }}m-{{ $uuid }}" name="memberships[{{ $uuid }}]">
                                    <option value="none" @selected($current === 'none')>Not assigned</option>
                                    <option value="project_manager" @selected($current === 'project_manager')>Project manager</option>
                                    <option value="auditor" @selected($current === 'auditor')>Auditor</option>
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @error('memberships') <p class="field-error">{{ $message }}</p> @enderror
</fieldset>
@endif
