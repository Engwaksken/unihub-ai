<x-layouts.app title="Recovery codes">
    <div class="page-header">
        <div>
            <p class="breadcrumb"><a href="{{ route('account.show') }}">Your account</a></p>
            <h1>Recovery codes</h1>
        </div>
    </div>
    <section class="card">
        <p>Each code can be used once to sign in if you cannot use your authenticator app. Keep them somewhere safe.</p>
        <ul class="recovery-codes">
            @foreach ($codes as $code)
                <li><code>{{ $code }}</code></li>
            @endforeach
        </ul>
    </section>
</x-layouts.app>
