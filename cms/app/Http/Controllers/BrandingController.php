<?php

namespace App\Http\Controllers;

use App\Services\CmsSettings;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the uploaded logo and favicon (Settings → Appearance). Public, because the
 * sign-in page shows them too. Only the two configured files can be served: the path
 * comes from the settings, never from the request.
 */
class BrandingController extends Controller
{
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];

    public function show(string $kind, CmsSettings $cms): BinaryFileResponse
    {
        $path = $cms->get($kind === 'favicon' ? 'favicon_path' : 'logo_path');
        abort_unless(is_string($path) && str_starts_with($path, 'branding/') && Storage::disk('local')->exists($path), 404);
        $type = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        abort_if($type === null, 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $type,
            // URLs carry ?v=<hash of the stored name>, so a new upload gets a new URL.
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
