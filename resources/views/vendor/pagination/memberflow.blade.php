@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="mt-6 flex flex-col items-center justify-between gap-3 sm:flex-row">
        <p class="text-sm text-muted">
            Showing <span class="font-bold text-ink">{{ $paginator->firstItem() }}</span>–<span class="font-bold text-ink">{{ $paginator->lastItem() }}</span>
            of <span class="font-bold text-ink">{{ $paginator->total() }}</span>
        </p>
        <ul class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <li><span class="btn-secondary btn-sm opacity-50" aria-disabled="true">Previous</span></li>
            @else
                <li><a class="btn-secondary btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a></li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="px-2 text-sm text-muted">{{ $element }}</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li><span class="btn-dark btn-sm" aria-current="page">{{ $page }}</span></li>
                        @else
                            <li><a class="btn-secondary btn-sm" href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li><a class="btn-secondary btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a></li>
            @else
                <li><span class="btn-secondary btn-sm opacity-50" aria-disabled="true">Next</span></li>
            @endif
        </ul>
    </nav>
@endif
