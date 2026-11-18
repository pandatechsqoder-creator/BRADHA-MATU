@if (! empty($chrome['banner']['title']))
    <aside class="site-banner" role="region" aria-label="Announcement">
        <div class="container site-banner-inner">
            <div>
                <strong>{{ $chrome['banner']['title'] }}</strong>
                @if (! empty($chrome['banner']['body']))
                    <span>{{ $chrome['banner']['body'] }}</span>
                @endif
            </div>
            @if (! empty($chrome['banner']['url']))
                <a href="{{ $chrome['banner']['url'] }}">Learn more</a>
            @endif
        </div>
    </aside>
@endif
