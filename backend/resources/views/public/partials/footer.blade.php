@php
    $brand = $chrome['brand'];
    $contact = $chrome['contact'];
    $hasContact = $contact['email'] || $contact['phone'] || $contact['whatsapp'] || $contact['address'];
@endphp
<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-brand">
            <p class="footer-brand-name">{{ $brand['name'] }}</p>
            <p class="footer-blurb">{{ $brand['short_description'] }}</p>
            <a class="button button--ghost button--small" href="{{ url('/request-quote') }}">Request a stock-taking quote</a>
        </div>

        @if (! empty($chrome['footer_menu']))
            <nav class="footer-column" aria-label="Footer">
                <h2 class="footer-heading">Explore</h2>
                <ul>
                    @foreach ($chrome['footer_menu'] as $item)
                        <li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif

        <div class="footer-column">
            <h2 class="footer-heading">Contact</h2>
            @if ($hasContact)
                <ul class="footer-contact">
                    @if ($contact['email'])
                        <li><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></li>
                    @endif
                    @if ($contact['phone'])
                        <li><a href="tel:{{ preg_replace('/\s+/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a></li>
                    @endif
                    @if ($contact['whatsapp_url'])
                        <li><a href="{{ $contact['whatsapp_url'] }}" rel="noopener" target="_blank">WhatsApp {{ $contact['whatsapp'] }}</a></li>
                    @endif
                    @if ($contact['address'])
                        <li>{{ $contact['address'] }}</li>
                    @endif
                </ul>
            @else
                <p class="footer-muted">Contact details are being confirmed and will be published here before launch. Use the quote request page to tell us about your stock-taking needs.</p>
            @endif

            @if (! empty($chrome['social']))
                <ul class="footer-social">
                    @foreach ($chrome['social'] as $network => $url)
                        <li><a href="{{ $url }}" rel="noopener" target="_blank">{{ ucfirst($network) }}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="container footer-bottom">
        <p>&copy; {{ date('Y') }} {{ $brand['name'] }}. All rights reserved.</p>
        @if (! empty($chrome['legal_menu']))
            <ul class="footer-legal">
                @foreach ($chrome['legal_menu'] as $item)
                    <li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>
                @endforeach
            </ul>
        @endif
        <button type="button" class="footer-consent-link" data-consent-reopen hidden>Cookie preferences</button>
    </div>
</footer>
