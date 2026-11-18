@php
    $heading = $content['heading'] ?? null;
    $intro = $content['intro'] ?? null;
    $items = $content['items'] ?? [];
    $headingId = $heading ? \Illuminate\Support\Str::slug($heading) : null;
@endphp
@if ($heading || ! empty($items))
    <section class="section section--tint" @if ($headingId) aria-labelledby="{{ $headingId }}" @endif>
        <div class="container">
            <div class="section-head">
                @if ($heading)
                    <h2 id="{{ $headingId }}">{{ $heading }}</h2>
                @endif
                @if ($intro)
                    <p class="section-intro">{{ $intro }}</p>
                @endif
            </div>
            <div class="card-grid">
                @foreach ($items as $item)
                    <article class="card">
                        @if (! empty($item['title']))
                            <h3>
                                @if (! empty($item['url']))
                                    <a href="{{ $item['url'] }}">{{ $item['title'] }}</a>
                                @else
                                    {{ $item['title'] }}
                                @endif
                            </h3>
                        @endif
                        @if (! empty($item['body']))
                            <p>{{ $item['body'] }}</p>
                        @endif
                        @if (! empty($item['url']))
                            <a class="card-link" href="{{ $item['url'] }}">Learn more<span class="visually-hidden"> about {{ $item['title'] ?? '' }}</span></a>
                        @endif
                    </article>
                @endforeach
            </div>
        </div>
    </section>
@endif
