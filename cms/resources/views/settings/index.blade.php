<x-layouts.app title="Settings">
    @php
        $fontLabel = $fonts[$cms['font'] ?? 'poppins']['label'] ?? 'Poppins (default)';
        $appearanceOpen = old('_modal') === 'appearance';
        $portalOpen = old('_modal') === 'portal';
        $policyOpen = old('_modal') === 'policy';
        $groupKeys = [];
        foreach (array_keys($fields) as $g) { $groupKeys[\Illuminate\Support\Str::slug($g)] = $g; }
    @endphp
    <div class="page-header">
        <div>
            <h1>Settings</h1>
            <p class="muted">Portal and appearance settings are stored in the CMS; gateway policy is stored on the gateway. Every change is audited.</p>
        </div>
    </div>
    @if ($errors->any() && ! $appearanceOpen && ! $portalOpen && ! $policyOpen) <x-form-errors /> @endif

    {{-- ---------- Portal ---------- --}}
    <section class="card" aria-labelledby="portal-heading">
        <div class="section-head">
            <h2 id="portal-heading">Portal</h2>
            @if ($canEditCms)
                <x-modal name="portal" title="Portal settings" trigger-label="Edit portal settings" trigger-class="btn btn-secondary btn-sm" size="lg">
                    <form method="POST" action="{{ route('settings.cms') }}" class="form">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="_modal" value="portal">
                        <x-form-errors bag="cmsSettings" />
                        <x-tabs name="portal-tabs" aria-label="Portal settings" bag="cmsSettings"
                                :tabs="['general' => 'General', 'alerts' => 'Alert e-mails']"
                                :fields="['general' => ['brand_name', 'display_timezone'], 'alerts' => ['notification_recipients']]">
                            <x-tab-panel tabs="portal-tabs" key="general">
                                <div class="form-grid">
                                    <div class="field">
                                        <label for="brand_name">Portal name</label>
                                        <input id="brand_name" name="brand_name" type="text" maxlength="60" required value="{{ $portalOpen ? old('brand_name') : $cms['brand_name'] }}">
                                        @error('brand_name', 'cmsSettings') <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="field">
                                        <label for="display_timezone">Display time zone</label>
                                        <select id="display_timezone" name="display_timezone" aria-describedby="tz-help">
                                            @foreach ($timezones as $tz)
                                                <option value="{{ $tz }}" @selected(($portalOpen ? old('display_timezone') : $cms['display_timezone']) === $tz)>{{ $tz }}</option>
                                            @endforeach
                                        </select>
                                        <p id="tz-help" class="field-help">Times are stored in UTC and shown in this zone.</p>
                                        @error('display_timezone', 'cmsSettings') <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </x-tab-panel>
                            <x-tab-panel tabs="portal-tabs" key="alerts">
                                <div class="field">
                                    <label for="notification_recipients">Alert e-mail recipients</label>
                                    <textarea id="notification_recipients" name="notification_recipients" rows="5" aria-describedby="recipients-help">{{ $portalOpen ? old('notification_recipients') : implode("\n", $cms['notification_recipients'] ?? []) }}</textarea>
                                    <p id="recipients-help" class="field-help">One address per line (up to 20). They receive each new gateway alert once, and a CMS alert if the gateway is unreachable for 2 minutes.</p>
                                    @error('notification_recipients', 'cmsSettings') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                            </x-tab-panel>
                        </x-tabs>
                        <div class="dialog-actions">
                            <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save portal settings</button>
                        </div>
                    </form>
                </x-modal>
            @endif
        </div>
        <dl class="details">
            <div><dt>Portal name</dt><dd>{{ $cms['brand_name'] }}</dd></div>
            <div><dt>Display time zone</dt><dd>{{ $cms['display_timezone'] }}</dd></div>
            <div><dt>Alert e-mail recipients</dt><dd>{{ empty($cms['notification_recipients']) ? 'None' : implode(', ', $cms['notification_recipients']) }}</dd></div>
        </dl>

        <div class="details-inline">
            <h3 class="small">E-mail delivery</h3>
            <p class="muted small">Invitations, password resets and alerts are sent through <code>{{ config('mail.mailers.'.config('mail.default').'.host') ?: config('mail.default') }}</code> as <code>{{ config('mail.from.address') }}</code>.</p>
            <x-form-errors bag="mailTest" />
            @if ($canEditCms)
                <form method="POST" action="{{ route('settings.test-mail') }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">Send test e-mail to {{ auth()->user()->email }}</button>
                </form>
            @endif
        </div>
    </section>

    {{-- ---------- Appearance ---------- --}}
    <section class="card" aria-labelledby="appearance-heading">
        <div class="section-head">
            <h2 id="appearance-heading">Appearance</h2>
            @if ($canEditCms)
                <x-modal name="appearance" title="Appearance" trigger-label="Edit appearance" trigger-class="btn btn-secondary btn-sm" size="lg">
                    <form method="POST" action="{{ route('settings.appearance') }}" class="form" enctype="multipart/form-data" x-data="{ busy: false }" x-on:submit="busy = true">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="_modal" value="appearance">
                        <x-form-errors bag="appearance" />
                        <x-tabs name="appearance-tabs" aria-label="Appearance" bag="appearance"
                                :tabs="['colours' => 'Colours', 'type' => 'Font & layout', 'images' => 'Logo & favicon']"
                                :fields="['colours' => ['primary_color', 'accent_color'], 'type' => ['font', 'density'], 'images' => ['logo', 'favicon']]">
                            <x-tab-panel tabs="appearance-tabs" key="colours">
                                <div class="form-grid">
                                    <div class="field">
                                        <label for="primary_color">Primary colour</label>
                                        <div class="color-field">
                                            <input id="primary_color" name="primary_color" type="color" value="{{ $appearanceOpen ? old('primary_color') : $cms['primary_color'] }}" aria-describedby="primary-help">
                                            <code>{{ $appearanceOpen ? old('primary_color') : $cms['primary_color'] }}</code>
                                        </div>
                                        <p id="primary-help" class="field-help">Top bar, buttons, links and headings. Must be dark enough for white text (contrast at least 4.5:1).</p>
                                        @error('primary_color', 'appearance') <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="field">
                                        <label for="accent_color">Accent colour</label>
                                        <div class="color-field">
                                            <input id="accent_color" name="accent_color" type="color" value="{{ $appearanceOpen ? old('accent_color') : $cms['accent_color'] }}" aria-describedby="accent-help">
                                            <code>{{ $appearanceOpen ? old('accent_color') : $cms['accent_color'] }}</code>
                                        </div>
                                        <p id="accent-help" class="field-help">Borders, active tabs and highlights (never used for text).</p>
                                        @error('accent_color', 'appearance') <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                                <p class="field-help">Default: primary <code>{{ \App\Services\CmsSettings::DEFAULT_PRIMARY }}</code>, accent <code>{{ \App\Services\CmsSettings::DEFAULT_ACCENT }}</code>.</p>
                            </x-tab-panel>
                            <x-tab-panel tabs="appearance-tabs" key="type">
                                <div class="form-grid">
                                    <div class="field">
                                        <label for="font">Font</label>
                                        <select id="font" name="font">
                                            @foreach ($fonts as $value => $font)
                                                <option value="{{ $value }}" @selected(($appearanceOpen ? old('font') : $cms['font']) === $value) style="font-family: {{ $font['stack'] }}">{{ $font['label'] }}</option>
                                            @endforeach
                                        </select>
                                        @error('font', 'appearance') <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="field">
                                        <label for="density">Layout density</label>
                                        <select id="density" name="density" aria-describedby="density-help">
                                            @foreach ($densities as $value => $label)
                                                <option value="{{ $value }}" @selected(($appearanceOpen ? old('density') : $cms['density']) === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <p id="density-help" class="field-help">Compact fits more rows and fields on a screen.</p>
                                        @error('density', 'appearance') <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </x-tab-panel>
                            <x-tab-panel tabs="appearance-tabs" key="images">
                                <div class="form-grid">
                                    <div class="field">
                                        <label for="logo">Logo</label>
                                        <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="logo-help">
                                        <p id="logo-help" class="field-help">PNG, JPG or WebP, up to 1 MB and 2000×2000 px. Shown at 32 px high in the top bar and on the sign-in page.</p>
                                        @if (! empty($cms['logo_path']))
                                            <label class="checkbox"><input type="checkbox" name="remove_logo" value="1"> <span>Remove the current logo</span></label>
                                        @endif
                                        @error('logo', 'appearance') <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="field">
                                        <label for="favicon">Favicon</label>
                                        <input id="favicon" name="favicon" type="file" accept="image/png,image/x-icon,.ico" aria-describedby="favicon-help">
                                        <p id="favicon-help" class="field-help">PNG or ICO, up to 256 KB (32×32 or 48×48 px works best).</p>
                                        @if (! empty($cms['favicon_path']))
                                            <label class="checkbox"><input type="checkbox" name="remove_favicon" value="1"> <span>Remove the current favicon</span></label>
                                        @endif
                                        @error('favicon', 'appearance') <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </x-tab-panel>
                        </x-tabs>
                        <div class="dialog-actions">
                            <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Cancel</button>
                            <button type="submit" class="btn btn-primary" x-bind:disabled="busy">Save appearance</button>
                        </div>
                    </form>
                </x-modal>
            @endif
        </div>
        <dl class="details">
            <div><dt>Colours</dt><dd><span class="swatch" style="background: {{ $cms['primary_color'] }}"></span> <code>{{ $cms['primary_color'] }}</code> &nbsp; <span class="swatch" style="background: {{ $cms['accent_color'] }}"></span> <code>{{ $cms['accent_color'] }}</code></dd></div>
            <div><dt>Font</dt><dd>{{ $fontLabel }}</dd></div>
            <div><dt>Layout density</dt><dd>{{ $densities[$cms['density'] ?? 'comfortable'] ?? 'Comfortable' }}</dd></div>
            <div><dt>Logo</dt><dd>
                @if (! empty($cms['logo_path']))
                    <img class="brand-logo-preview" src="{{ route('branding', ['kind' => 'logo', 'v' => app(\App\Services\CmsSettings::class)->assetVersion('logo_path')]) }}" alt="Current logo">
                @else <span class="muted">Not set (initial badge)</span> @endif
            </dd></div>
            <div><dt>Favicon</dt><dd>
                @if (! empty($cms['favicon_path']))
                    <img class="brand-logo-preview" src="{{ route('branding', ['kind' => 'favicon', 'v' => app(\App\Services\CmsSettings::class)->assetVersion('favicon_path')]) }}" alt="Current favicon" width="24" height="24">
                @else <span class="muted">Not set</span> @endif
            </dd></div>
        </dl>
    </section>

    {{-- ---------- Gateway policy ---------- --}}
    @can('settings.view')
        <section class="card" aria-labelledby="policy-heading">
            <div class="section-head">
                <h2 id="policy-heading">Gateway policy</h2>
                @if (! $error && $canEditPolicy)
                    <x-modal name="policy" title="Gateway policy" trigger-label="Edit gateway policy" trigger-class="btn btn-secondary btn-sm" size="lg">
                        <form method="POST" action="{{ route('settings.policy') }}" class="form" x-data="{ busy: false }" x-on:submit="busy = true">
                            @csrf
                            @method('PATCH')
                            <x-idempotency-key />
                            <input type="hidden" name="_modal" value="policy">
                            <x-form-errors />
                            <x-tabs name="policy" aria-label="Gateway policy sections" :tabs="$groupKeys"
                                    :fields="collect($fields)->mapWithKeys(fn ($f, $g) => [\Illuminate\Support\Str::slug($g) => array_keys($f)])->all()">
                                @foreach ($fields as $group => $groupFields)
                                    <x-tab-panel tabs="policy" :key="\Illuminate\Support\Str::slug($group)">
                                        <div class="form-grid form-grid-3">
                                            @foreach ($groupFields as $key => $def)
                                                <div class="field">
                                                    <label for="s-{{ $key }}">{{ $def['label'] }}</label>
                                                    <input id="s-{{ $key }}" name="{{ $key }}" type="number" required
                                                           min="{{ $def['min'] }}" max="{{ $def['max'] }}" step="{{ $def['type'] === 'numeric' ? '0.01' : '1' }}"
                                                           value="{{ $policyOpen ? old($key) : (is_scalar($policy[$key] ?? null) ? $policy[$key] : '') }}"
                                                           @error($key) aria-invalid="true" @enderror
                                                           @isset($def['help']) aria-describedby="h-{{ $key }}" @endisset>
                                                    @isset($def['help']) <p id="h-{{ $key }}" class="field-help">{{ $def['help'] }}</p> @endisset
                                                    @error($key) <p class="field-error">{{ $message }}</p> @enderror
                                                </div>
                                            @endforeach
                                        </div>
                                    </x-tab-panel>
                                @endforeach
                            </x-tabs>
                            <div class="dialog-actions">
                                <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Cancel</button>
                                <button type="submit" class="btn btn-primary" x-bind:disabled="busy">Save gateway settings</button>
                            </div>
                        </form>
                    </x-modal>
                @endif
            </div>
            @if ($error)
                <x-gateway-unavailable :exception="$error" />
            @else
                @unless ($canEditPolicy)
                    <p class="muted">Only an owner can change gateway policy settings.</p>
                @endunless
                <div class="table-wrap">
                    <table class="table table-compact">
                        <caption class="visually-hidden">Gateway policy values</caption>
                        <thead><tr><th scope="col">Setting</th><th scope="col" class="num">Value</th><th scope="col">Group</th></tr></thead>
                        <tbody>
                            @foreach ($fields as $group => $groupFields)
                                @foreach ($groupFields as $key => $def)
                                    <tr>
                                        <th scope="row">{{ $def['label'] }}</th>
                                        <td class="num">{{ is_scalar($policy[$key] ?? null) ? $policy[$key] : '—' }}</td>
                                        <td class="muted small">{{ $group }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <dl class="details details-inline">
                    <div><dt>Admission</dt><dd>{{ ucfirst((string) ($policy['admission'] ?? 'unknown')) }} <span class="muted small">(change on Server &amp; alerts)</span></dd></div>
                    <div><dt>Approved external providers</dt><dd>{{ empty($policy['approved_providers']) ? 'None (external fallback disabled)' : implode(', ', array_map('strval', (array) $policy['approved_providers'])) }}</dd></div>
                </dl>
            @endif
        </section>
    @endcan
</x-layouts.app>
