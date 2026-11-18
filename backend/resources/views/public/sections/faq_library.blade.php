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
            <div class="faq-list">
                @foreach ($items as $item)
                    @if (! empty($item['question']))
                        <details class="faq-item">
                            <summary>{{ $item['question'] }}</summary>
                            @if (! empty($item['answer']))
                                <div class="faq-answer">
                                    <p>{{ $item['answer'] }}</p>
                                </div>
                            @endif
                        </details>
                    @endif
                @endforeach
            </div>
        </div>
    </section>
@endif
