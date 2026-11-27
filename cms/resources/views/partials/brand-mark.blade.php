{{-- Logo from Settings → Appearance, or the portal name's initial as a badge. --}}
@php($appearance = app(\App\Services\CmsSettings::class))
@if ($appearance->hasLogo())
    <img class="brand-logo" src="{{ route('branding', ['kind' => 'logo', 'v' => $appearance->assetVersion('logo_path')]) }}" alt="" height="32">
@else
    <span class="brand-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($appearance->brandName(), 0, 1)) }}</span>
@endif
<span>{{ $appearance->brandName() }}</span>
