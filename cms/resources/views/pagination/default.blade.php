@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true">Previous</span>
        @else
            <a class="btn btn-secondary btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
        @endif
        <span class="pager-info">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())
            <a class="btn btn-secondary btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
        @else
            <span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true">Next</span>
        @endif
    </nav>
@endif
