@php
    $heading = $content['heading'] ?? null;
    $body = $content['body'] ?? null;
    $primary = $content['primary_cta'] ?? null;
    $secondary = $content['secondary_cta'] ?? null;
@endphp
@if ($heading)
    <section class="section cta-band">
        <div class="container cta-inner">
            <div>
                <h2>{{ $heading }}</h2>
                @if ($body)
                    <p>{{ $body }}</p>
                @endif
            </div>
            @if ($primary || $secondary)
                <div class="button-row button-row--end">
                    @if ($primary)
                        <a class="button button--primary" href="{{ $primary['url'] ?? url('/request-quote') }}">{{ $primary['label'] ?? 'Request a quote' }}</a>
                    @endif
                    @if ($secondary)
                        <a class="button button--ghost" href="{{ $secondary['url'] ?? url('/book-demo') }}">{{ $secondary['label'] ?? 'Book a demo' }}</a>
                    @endif
                </div>
            @endif
        </div>
    </section>
@endif
