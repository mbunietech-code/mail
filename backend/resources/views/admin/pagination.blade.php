@if ($paginator->hasPages())
<nav class="pager" aria-label="Pagination">
    <span class="muted">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
    <span class="actions">
        @if ($paginator->onFirstPage())<span class="button secondary sm" aria-disabled="true">Previous</span>@else<a class="button secondary sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>@endif
        @if ($paginator->hasMorePages())<a class="button secondary sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>@else<span class="button secondary sm" aria-disabled="true">Next</span>@endif
    </span>
</nav>
@endif
