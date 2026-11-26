@props(['title', 'icon' => 'folder'])
<div class="state">
    <x-icon :name="$icon" class="state-icon" />
    <h2 class="state-title">{{ $title }}</h2>
    <div class="state-body">{{ $slot }}</div>
</div>
