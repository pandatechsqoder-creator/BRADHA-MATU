@php
    $heading = $content['heading'] ?? null;
    $paragraphs = $content['paragraphs'] ?? [];
    $bullets = $content['bullets'] ?? [];
    $cta = $content['cta'] ?? null;
    $headingId = $heading ? \Illuminate\Support\Str::slug($heading) : null;
@endphp
@if ($heading || ! empty($paragraphs) || ! empty($bullets) || $cta)
    <section class="section" @if ($headingId) aria-labelledby="{{ $headingId }}" @endif>
        <div class="container prose">
            @if ($heading)
                <h2 id="{{ $headingId }}">{{ $heading }}</h2>
            @endif
            @foreach ($paragraphs as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
            @if (! empty($bullets))
                <ul class="check-list">
                    @foreach ($bullets as $bullet)
                        <li>{{ $bullet }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($cta)
                <p><a href="{{ url($cta['url']) }}">{{ $cta['label'] }}</a></p>
            @endif
        </div>
    </section>
@endif
