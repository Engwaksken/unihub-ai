@php use App\Support\Fmt; @endphp
<x-layouts.app title="Models">
    <div class="page-header">
        <div>
            <h1>Models &amp; tasks</h1>
            <p class="muted">Models installed on the inference host. The portal never pulls, deletes or runs commands; refresh only re-reads what is installed.</p>
        </div>
        @can('model.enable')
            <form method="POST" action="{{ route('models.refresh') }}" x-data="{ busy: false }" x-on:submit="busy = true">
                @csrf
                <x-idempotency-key />
                <button type="submit" class="btn btn-secondary" x-bind:disabled="busy">
                    <span x-show="! busy">Refresh installed models</span><span x-show="busy" x-cloak>Refreshing…</span>
                </button>
            </form>
        @endcan
    </div>
    @include('models._tabs')
    <x-form-errors />

    @if ($error)
        <x-gateway-unavailable :exception="$error" />
    @elseif ($models === [])
        <x-empty-state title="No models recorded" icon="cpu"><p>No installed models are known yet. An ops admin can refresh the list from the host.</p></x-empty-state>
    @else
        <div class="table-wrap">
            <table class="table">
                <caption class="visually-hidden">Installed models</caption>
                <thead>
                    <tr>
                        <th scope="col">Tag</th>
                        <th scope="col">Digest</th>
                        <th scope="col" class="num">Size</th>
                        <th scope="col">Licence</th>
                        <th scope="col">Installed</th>
                        <th scope="col">Enabled</th>
                        <th scope="col">Checked</th>
                        @can('model.enable')<th scope="col"><span class="visually-hidden">Actions</span></th>@endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach ($models as $m)
                        <tr>
                            <th scope="row"><code>{{ $m['tag'] ?? '' }}</code></th>
                            <td><code class="small">{{ \Illuminate\Support\Str::limit((string) ($m['digest'] ?? ''), 19, '…') ?: '—' }}</code></td>
                            <td class="num">{{ Fmt::bytes($m['size_bytes'] ?? null) }}</td>
                            <td>{{ Fmt::str($m['licence'] ?? null, 'Not recorded') }}</td>
                            <td>{{ ! empty($m['installed']) ? 'Yes' : 'No' }}</td>
                            <td><x-status-badge :status="! empty($m['enabled']) ? 'active' : 'disabled'" /></td>
                            <td><x-time :value="$m['checked_at'] ?? null" /></td>
                            @can('model.enable')
                                <td class="row-actions">
                                    @if (! empty($m['enabled']))
                                        <x-confirm-dialog :title="'Disable '.($m['tag'] ?? 'model').'?'" :action="route('models.update', $m['id'] ?? '')" method="PATCH"
                                                          trigger-label="Disable" confirm-label="Disable model">
                                            <x-slot:fields><input type="hidden" name="enabled" value="0"></x-slot:fields>
                                            Tasks using this model cannot run or be published until a model is enabled again.
                                        </x-confirm-dialog>
                                    @elseif (! empty($m['installed']))
                                        <div x-data class="confirm">
                                            <button type="button" class="btn btn-secondary btn-sm" x-on:click="$refs.enable.showModal()" aria-haspopup="dialog">Enable…</button>
                                            <dialog x-ref="enable" class="dialog" aria-labelledby="enable-{{ $loop->index }}-title">
                                                <form method="POST" action="{{ route('models.update', $m['id'] ?? '') }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <x-idempotency-key />
                                                    <input type="hidden" name="enabled" value="1">
                                                    <h2 id="enable-{{ $loop->index }}-title" class="dialog-title">Enable {{ $m['tag'] ?? '' }}</h2>
                                                    <p class="dialog-body">Only one model can be enabled on the 4 GB host. Record the licence before enabling.</p>
                                                    <div class="field">
                                                        <label for="licence-{{ $loop->index }}">Licence</label>
                                                        <input id="licence-{{ $loop->index }}" name="licence" type="text" maxlength="120" required value="{{ $m['licence'] ?? '' }}" placeholder="e.g. Apache-2.0">
                                                    </div>
                                                    <div class="dialog-actions">
                                                        <button type="button" class="btn btn-secondary" x-on:click="$refs.enable.close()">Cancel</button>
                                                        <button type="submit" class="btn btn-primary">Enable model</button>
                                                    </div>
                                                </form>
                                            </dialog>
                                        </div>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
