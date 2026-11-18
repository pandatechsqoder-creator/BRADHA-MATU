@php
    $heading = $content['heading'] ?? null;
    $body = $content['body'] ?? null;
    $items = $content['items'] ?? [];
    $cta = $content['cta'] ?? ['label' => 'Request a quote', 'url' => '/request-quote'];
    $headingId = $heading ? \Illuminate\Support\Str::slug($heading) : null;
    $hasContact = $contact['email'] || $contact['phone'] || $contact['whatsapp_url'];
@endphp
@if ($heading || $body)
    <section class="section" @if ($headingId) aria-labelledby="{{ $headingId }}" @endif>
        <div class="container contact-grid">
            <div>
                @if ($heading)
                    <h2 id="{{ $headingId }}">{{ $heading }}</h2>
                @endif
                @if ($body)
                    <p class="lead">{{ $body }}</p>
                @endif
                @if (! empty($items))
                    <ul class="check-list">
                        @foreach ($items as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="contact-card">
                @if ($hasContact)
                    <ul class="contact-list">
                        @if ($contact['email'])
                            <li><span>Email</span><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></li>
                        @endif
                        @if ($contact['phone'])
                            <li><span>Phone</span><a href="tel:{{ preg_replace('/\s+/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a></li>
                        @endif
                        @if ($contact['whatsapp_url'])
                            <li><span>WhatsApp</span><a href="{{ $contact['whatsapp_url'] }}" rel="noopener" target="_blank">{{ $contact['whatsapp'] }}</a></li>
                        @endif
                    </ul>
                @else
                    <p class="muted">Direct contact details are being confirmed. In the meantime, use the quote request page to share your requirements and preferred dates.</p>
                @endif
                <a class="button button--primary" href="{{ url($cta['url']) }}">{{ $cta['label'] }}</a>
            </div>
        </div>
    </section>
@endif
