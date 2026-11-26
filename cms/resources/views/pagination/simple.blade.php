@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pagination">
        @if (! $paginator->onFirstPage())
            <a class="btn btn-secondary btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">Newer</a>
        @endif
        @if ($paginator->hasMorePages())
            <a class="btn btn-secondary btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Older</a>
        @endif
    </nav>
@endif
