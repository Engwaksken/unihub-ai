<nav class="tabs" aria-label="Models and tasks">
    <a href="{{ route('models.index') }}" @class(['tab', 'is-active' => request()->routeIs('models.*')]) @if (request()->routeIs('models.*')) aria-current="page" @endif>Models</a>
    <a href="{{ route('tasks.index') }}" @class(['tab', 'is-active' => request()->routeIs('tasks.*')]) @if (request()->routeIs('tasks.*')) aria-current="page" @endif>Tasks</a>
</nav>
