{{-- Favicon and colour/font overrides from Settings → Appearance (validated values only). --}}
@php($appearance = app(\App\Services\CmsSettings::class))
@if ($appearance->hasFavicon())
    <link rel="icon" href="{{ route('branding', ['kind' => 'favicon', 'v' => $appearance->assetVersion('favicon_path')]) }}">
@endif
@if (($themeCss = $appearance->themeCss()) !== '')
    <style>{!! $themeCss !!}</style>
@endif
