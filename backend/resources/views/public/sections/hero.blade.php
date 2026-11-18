@php
    $eyebrow = $content['eyebrow'] ?? null;
    $headline = $content['headline'] ?? null;
    $subheadline = $content['subheadline'] ?? null;
    $bullets = $content['bullets'] ?? [];
    $primary = $content['primary_cta'] ?? null;
    $secondary = $content['secondary_cta'] ?? null;
    $panel = $content['panel'] ?? null;
@endphp
@if ($headline)
    <section class="section hero">
        <div class="container hero-grid">
            <div class="hero-copy">
                @if ($eyebrow)
                    <p class="eyebrow">{{ $eyebrow }}</p>
                @endif
                <h1>{{ $headline }}</h1>
                @if ($subheadline)
                    <p class="lead">{{ $subheadline }}</p>
                @endif
                @if (! empty($bullets))
                    <ul class="check-list">
                        @foreach ($bullets as $bullet)
                            <li>{{ $bullet }}</li>
                        @endforeach
                    </ul>
                @endif
                @if ($primary || $secondary)
                    <div class="button-row">
                        @if ($primary)
                            <a class="button button--primary" href="{{ $primary['url'] ?? url('/request-quote') }}">{{ $primary['label'] ?? 'Request a quote' }}</a>
                        @endif
                        @if ($secondary)
                            <a class="button button--secondary" href="{{ $secondary['url'] ?? url('/brathamatu-pos') }}">{{ $secondary['label'] ?? 'Learn more' }}</a>
                        @endif
                    </div>
                @endif
            </div>

            @if (! empty($panel['title']) || ! empty($panel['items']))
                <aside class="hero-panel" aria-label="{{ $panel['title'] ?? 'Service options' }}">
                    @if (! empty($panel['title']))
                        <p class="hero-panel-title">{{ $panel['title'] }}</p>
                    @endif
                    @if (! empty($panel['items']))
                        <ul>
                            @foreach ($panel['items'] as $item)
                                <li>
                                    <span class="hero-panel-dot" aria-hidden="true"></span>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </aside>
            @endif
        </div>
    </section>
@endif
