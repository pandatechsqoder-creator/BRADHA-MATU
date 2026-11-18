@extends('public.layout')

@section('content')
    @include('public.partials.breadcrumbs', ['items' => $page['breadcrumbs']])

    <article class="section article-page" data-testid="article-page">
        <div class="container article-layout">
            <header class="article-header">
                @if ($article['category'])
                    <a class="article-category" href="{{ route('resources.category', $article['category']['slug']) }}">{{ $article['category']['name'] }}</a>
                @endif
                <h1>{{ $article['title'] }}</h1>
                <p class="article-lede">{{ $article['excerpt'] }}</p>
                <p class="article-meta">
                    <span>By {{ $article['author'] }}</span>
                    <span aria-hidden="true">·</span>
                    <time datetime="{{ $article['published_at'] }}">{{ \Illuminate\Support\Carbon::parse($article['published_at'])->format('j M Y') }}</time>
                    <span aria-hidden="true">·</span>
                    <span>{{ $article['reading_minutes'] }} min read</span>
                </p>
            </header>

            @if ($article['image_url'])
                <img class="article-image" src="{{ $article['image_url'] }}" alt="">
            @endif

            <div class="prose article-body">
                {!! $body_html !!}
            </div>

            @if (! empty($article['tags']))
                <ul class="article-tags" aria-label="Article tags">
                    @foreach ($article['tags'] as $tag)
                        <li><a href="{{ route('resources.tag', $tag['slug']) }}">#{{ $tag['name'] }}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </article>

    @if (! empty($related))
        <section class="section section--tint" aria-labelledby="related-articles">
            <div class="container">
                <h2 id="related-articles">Related articles</h2>
                <div class="article-grid">
                    @foreach ($related as $item)
                        <article class="card article-card">
                            @if ($item['category'])
                                <a class="article-category" href="{{ route('resources.category', $item['category']['slug']) }}">{{ $item['category']['name'] }}</a>
                            @endif
                            <h3><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></h3>
                            <p>{{ $item['excerpt'] }}</p>
                            <a class="card-link" href="{{ $item['url'] }}">Read article</a>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="section cta-band">
        <div class="container cta-inner">
            <div>
                <h2>Planning a stock count?</h2>
                <p>Tell us about your locations and inventory process, and we will outline a stock-taking approach that fits.</p>
            </div>
            <div class="button-row button-row--end">
                <a class="button button--primary" href="{{ url('/request-quote') }}">Request a stock-taking quote</a>
                <a class="button button--ghost" href="{{ url('/contact') }}">Contact the team</a>
            </div>
        </div>
    </section>
@endsection
