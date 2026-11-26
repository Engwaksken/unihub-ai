<x-layouts.app :title="'Edit '.$subject->name">
    <div class="page-header">
        <div>
            <p class="breadcrumb"><a href="{{ route('users.index') }}">Users &amp; audit</a></p>
            <h1>{{ $subject->name }}</h1>
            <p class="muted">{{ $subject->email }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('users.update', $subject) }}" class="card form">
        @csrf
        @method('PUT')
        <x-form-errors />
        <div class="field form-narrow">
            <label for="name">Full name</label>
            <input id="name" name="name" type="text" maxlength="120" required value="{{ old('name', $subject->name) }}">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        @include('users._access', [
            'selectedRoles' => old('roles', $subject->roleValues()),
            'currentMemberships' => $subject->memberships->mapWithKeys(fn ($m) => [strtolower($m->gateway_project_uuid) => $m->role->value])->all(),
        ])

        <p class="field-help">Changes apply from the user’s next request; gateway permissions are re-signed on every call.</p>
        <div class="form-actions">
            <a href="{{ route('users.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save access</button>
        </div>
    </form>
</x-layouts.app>
