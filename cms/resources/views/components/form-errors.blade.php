@props(['bag' => 'default'])
@php($bagErrors = $errors->getBag($bag))
@if ($bagErrors->any())
    <div class="alert alert-error" role="alert" tabindex="-1">
        <p class="alert-title">Please fix the following:</p>
        <ul>
            @foreach ($bagErrors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
