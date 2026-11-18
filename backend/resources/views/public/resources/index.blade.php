@extends('public.layout')

@section('content')
    @include('public.partials.breadcrumbs', ['items' => $page['breadcrumbs']])

    @foreach ($page['sections'] as $section)
        @include('public.sections.'.$section['type'], [
            'content' => $section['content'],
            'settings' => $section['settings'],
            'contact' => $page['contact'],
            'brand' => $page['brand'],
        ])
    @endforeach

    <section class="section" aria-labelledby="resources-latest">
        <div class="container">
            <div class="resources-toolbar">
                <h2 id="resources-latest">Latest articles</h2>
                <form class="resources-search" method="get" action="{{ route('resources.index') }}" role="search">
                    <label class="visually-hidden" for="resources-q">Search articles</label>
                    <input id="resources-q" type="search" name="q" value="{{ $filters['q'] }}" placeholder="Search articles">
                    @if ($filters['category'] !== '')
                        <input type="hidden" name="category" value="{{ $filters['category'] }}">
                    @endif
                    <button class="button button--secondary button--small" type="submit">Search</button>
                </form>
            </div>

            @if (! empty($categories))
                <nav class="resources-categories" aria-label="Article categories">
                    <a class="resource-chip {{ $filters['category'] === '' ? 'is-active' : '' }}" href="{{ route('resources.index') }}">All</a>
                    @foreach ($categories as $category)
                        <a class="resource-chip {{ $filters['category'] === $category['slug'] ? 'is-active' : '' }}" href="{{ route('resources.category', $category['slug']) }}">
                            {{ $category['name'] }} <span aria-hidden="true">({{ $category['post_count'] }})</span>
                        </a>
                    @endforeach
                </nav>
            @endif

            @if ($filters['tag'] !== '')
                <p class="resources-note">Showing articles tagged <strong>{{ $filters['tag'] }}</strong>. <a href="{{ route('resources.index') }}">Clear filter</a></p>
            @endif

            @if (empty($articles))
                <p class="resources-empty">No articles match this filter yet. <a href="{{ route('resources.index') }}">View all articles</a>.</p>
            @else
                <div class="article-grid">
                    @foreach ($articles as $article)
                        <article class="card article-card" data-testid="article-card">
                            @if ($article['category'])
                                <a class="article-category" href="{{ route('resources.category', $article['category']['slug']) }}">{{ $article['category']['name'] }}</a>
                            @endif
                            <h3><a href="{{ $article['url'] }}">{{ $article['title'] }}</a></h3>
                            <p>{{ $article['excerpt'] }}</p>
                            <p class="article-meta">
                                <span>{{ $article['author'] }}</span>
                                <span aria-hidden="true">·</span>
                                <time datetime="{{ $article['published_at'] }}">{{ \Illuminate\Support\Carbon::parse($article['published_at'])->format('j M Y') }}</time>
                                <span aria-hidden="true">·</span>
                                <span>{{ $article['reading_minutes'] }} min read</span>
                            </p>
                            <a class="card-link" href="{{ $article['url'] }}">Read article</a>
                        </article>
                    @endforeach
                </div>
            @endif

            @if ($paginator->hasPages())
                <nav class="pagination" aria-label="Article pages">
                    @if ($paginator->onFirstPage())
                        <span class="pagination-link is-disabled">Previous</span>
                    @else
                        <a class="pagination-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
                    @endif
                    <span class="pagination-status">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
                    @if ($paginator->hasMorePages())
                        <a class="pagination-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
                    @else
                        <span class="pagination-link is-disabled">Next</span>
                    @endif
                </nav>
            @endif
        </div>
    </section>
@endsection
