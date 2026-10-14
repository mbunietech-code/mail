@if ($paginator->hasPages())
<nav class="actions" aria-label="Pagination">
    @if ($paginator->onFirstPage())<span class="muted">← Previous</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">← Previous</a>@endif
    <span class="muted">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
    @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Next →</a>@else<span class="muted">Next →</span>@endif
</nav>
@endif
