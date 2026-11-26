<x-layouts.app title="Settings">
    <div class="page-header">
        <div>
            <h1>Settings</h1>
            <p class="muted">Gateway policy settings are stored on the gateway; portal settings are stored in the CMS. Every change is audited.</p>
        </div>
    </div>
    <x-form-errors />

    <section class="card" aria-labelledby="portal-heading">
        <h2 id="portal-heading">Portal</h2>
        <form method="POST" action="{{ route('settings.cms') }}" class="form">
            @csrf
            @method('PATCH')
            <x-form-errors bag="cmsSettings" />
            <div class="form-grid">
                <div class="field">
                    <label for="brand_name">Portal name</label>
                    <input id="brand_name" name="brand_name" type="text" maxlength="60" required value="{{ old('brand_name', $cms['brand_name']) }}" @disabled(! $canEditCms)>
                    @error('brand_name', 'cmsSettings') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="display_timezone">Display time zone</label>
                    <select id="display_timezone" name="display_timezone" @disabled(! $canEditCms) aria-describedby="tz-help">
                        @foreach ($timezones as $tz)
                            <option value="{{ $tz }}" @selected(old('display_timezone', $cms['display_timezone']) === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                    <p id="tz-help" class="field-help">Times are stored in UTC and shown in this zone (default Africa/Kampala).</p>
                    @error('display_timezone', 'cmsSettings') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="field">
                <label for="notification_recipients">Alert e-mail recipients</label>
                <textarea id="notification_recipients" name="notification_recipients" rows="3" @disabled(! $canEditCms) aria-describedby="recipients-help">{{ old('notification_recipients', implode("\n", $cms['notification_recipients'] ?? [])) }}</textarea>
                <p id="recipients-help" class="field-help">One address per line (up to 20). They receive each new gateway alert once, and a CMS alert if the gateway is unreachable for 2 minutes.</p>
                @error('notification_recipients', 'cmsSettings') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            @if ($canEditCms)
                <div class="form-actions"><button type="submit" class="btn btn-primary">Save portal settings</button></div>
            @endif
        </form>
    </section>

    @can('settings.view')
        <section class="card" aria-labelledby="policy-heading">
            <h2 id="policy-heading">Gateway policy</h2>
            @if ($error)
                <x-gateway-unavailable :exception="$error" />
            @else
                @unless ($canEditPolicy)
                    <p class="muted">Only an owner can change gateway policy settings.</p>
                @endunless
                <form method="POST" action="{{ route('settings.policy') }}" class="form" x-data="{ busy: false }" x-on:submit="busy = true">
                    @csrf
                    @method('PATCH')
                    <x-idempotency-key />
                    @foreach ($fields as $group => $groupFields)
                        <fieldset class="fieldset" @disabled(! $canEditPolicy)>
                            <legend>{{ $group }}</legend>
                            <div class="form-grid form-grid-3">
                                @foreach ($groupFields as $key => $def)
                                    <div class="field">
                                        <label for="s-{{ $key }}">{{ $def['label'] }}</label>
                                        <input id="s-{{ $key }}" name="{{ $key }}" type="number" required
                                               min="{{ $def['min'] }}" max="{{ $def['max'] }}" step="{{ $def['type'] === 'numeric' ? '0.01' : '1' }}"
                                               value="{{ old($key, is_scalar($policy[$key] ?? null) ? $policy[$key] : '') }}"
                                               @error($key) aria-invalid="true" @enderror
                                               @isset($def['help']) aria-describedby="h-{{ $key }}" @endisset>
                                        @isset($def['help']) <p id="h-{{ $key }}" class="field-help">{{ $def['help'] }}</p> @endisset
                                        @error($key) <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                    @if ($canEditPolicy)
                        <div class="form-actions"><button type="submit" class="btn btn-primary" x-bind:disabled="busy">Save gateway settings</button></div>
                    @endif
                </form>

                <dl class="details details-inline">
                    <div><dt>Admission</dt><dd>{{ ucfirst((string) ($policy['admission'] ?? 'unknown')) }} <span class="muted small">(change on Server &amp; alerts)</span></dd></div>
                    <div><dt>Approved external providers</dt><dd>{{ empty($policy['approved_providers']) ? 'None (external fallback disabled in MVP)' : implode(', ', array_map('strval', (array) $policy['approved_providers'])) }}</dd></div>
                </dl>
            @endif
        </section>
    @endcan
</x-layouts.app>
