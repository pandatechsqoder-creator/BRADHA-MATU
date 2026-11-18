@php
    $brand = $chrome['brand'];
    $appUrl = $chrome['app_url'];
    $loginUrl = $appUrl !== '' ? $appUrl.'/login' : url('/login');
    $registerUrl = $appUrl !== '' ? $appUrl.'/register' : url('/register');
    $initials = collect(explode(' ', $brand['name']))
        ->filter()
        ->map(fn ($word) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($word, 0, 1)))
        ->take(2)
        ->implode('');
@endphp
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="{{ url('/') }}" aria-label="{{ $initials }} {{ $brand['name'] }} home">
            <span class="brand-mark" aria-hidden="true">{{ $initials }}</span>
            <span class="brand-text">
                <span class="brand-name">{{ $brand['name'] }}</span>
                <span class="brand-sub">{{ $brand['tagline'] }}</span>
            </span>
        </a>

        <nav class="nav-desktop" aria-label="Primary">
            <ul class="nav-list">
                @foreach ($chrome['primary_menu'] as $item)
                    <li class="nav-item">
                        <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif @if ($item['target'] === '_blank') target="_blank" rel="noopener" @endif>{{ $item['label'] }}</a>
                        @if (! empty($item['children']))
                            <ul class="nav-sublist">
                                @foreach ($item['children'] as $child)
                                    <li><a href="{{ $child['url'] }}" @if ($child['active']) aria-current="page" @endif>{{ $child['label'] }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="header-actions">
            <a class="header-login" href="{{ $loginUrl }}">Login</a>
            <a class="button button--primary button--small" href="{{ url('/request-quote') }}">Request a quote</a>
        </div>

        <details class="nav-mobile">
            <summary class="nav-mobile-toggle" aria-label="Open menu">Menu</summary>
            <nav class="nav-mobile-panel" aria-label="Mobile">
                <ul class="nav-mobile-list">
                    @foreach ($chrome['primary_menu'] as $item)
                        <li>
                            <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif @if ($item['target'] === '_blank') target="_blank" rel="noopener" @endif>{{ $item['label'] }}</a>
                            @if (! empty($item['children']))
                                <ul class="nav-mobile-sublist">
                                    @foreach ($item['children'] as $child)
                                        <li><a href="{{ $child['url'] }}" @if ($child['active']) aria-current="page" @endif>{{ $child['label'] }}</a></li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="nav-mobile-actions">
                    <a class="button button--primary" href="{{ url('/request-quote') }}">Request a quote</a>
                    <a class="button button--secondary" href="{{ $loginUrl }}">Login</a>
                    <a class="nav-mobile-register" href="{{ $registerUrl }}">Create an account</a>
                </div>
            </nav>
        </details>
    </div>
</header>
