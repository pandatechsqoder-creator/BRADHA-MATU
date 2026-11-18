@php
    $heading = $content['heading'] ?? null;
    $intro = $content['intro'] ?? null;
    $items = $content['items'] ?? [];
    $headingId = $heading ? \Illuminate\Support\Str::slug($heading) : null;
@endphp
@if ($heading || ! empty($items))
    <section class="section" @if ($headingId) aria-labelledby="{{ $headingId }}" @endif>
        <div class="container">
            <div class="section-head">
                @if ($heading)
                    <h2 id="{{ $headingId }}">{{ $heading }}</h2>
                @endif
                @if ($intro)
                    <p class="section-intro">{{ $intro }}</p>
                @endif
            </div>
            <div class="testimonial-grid">
                @foreach ($items as $item)
                    @if (! empty($item['quote']))
                        <figure class="testimonial">
                            @if (! empty($item['image_url']))
                                <img src="{{ $item['image_url'] }}" alt="" loading="lazy" width="56" height="56">
                            @endif
                            <blockquote>{{ $item['quote'] }}</blockquote>
                            <figcaption>
                                <strong>{{ $item['author_name'] ?? '' }}</strong>
                                @if (! empty($item['author_role']) || ! empty($item['business_name']))
                                    <span>{{ collect([$item['author_role'] ?? null, $item['business_name'] ?? null])->filter()->implode(', ') }}</span>
                                @endif
                            </figcaption>
                        </figure>
                    @endif
                @endforeach
            </div>
        </div>
    </section>
@endif
