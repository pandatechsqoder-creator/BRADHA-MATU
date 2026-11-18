<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @php
        $brand = $chrome['brand'];
        $seo = $page['seo'] ?? null;
        $pageTitle = $seo['title'] ?? (isset($title) ? $title.' | '.$brand['name'] : $brand['name'].' | '.$brand['tagline']);
        $description = $seo['description'] ?? $brand['short_description'];
        $robots = $seo['robots'] ?? 'noindex, nofollow';
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('meta_title', $pageTitle)</title>
    <meta name="description" content="@yield('meta_description', $description)">
    <meta name="robots" content="@yield('meta_robots', $robots)">
    @isset($seo['canonical'])
        <link rel="canonical" href="{{ $seo['canonical'] }}">
        <meta property="og:url" content="{{ $seo['canonical'] }}">
    @endisset
    <meta property="og:type" content="{{ $seo['og_type'] ?? 'website' }}">
    <meta property="og:site_name" content="{{ $brand['name'] }}">
    <meta property="og:title" content="{{ $seo['og_title'] ?? $pageTitle }}">
    <meta property="og:description" content="{{ $seo['og_description'] ?? $description }}">
    @isset($seo['og_image'])
        <meta property="og:image" content="{{ $seo['og_image'] }}">
        <meta name="twitter:image" content="{{ $seo['og_image'] }}">
    @endisset
    <meta name="twitter:card" content="{{ $seo['twitter_card'] ?? 'summary_large_image' }}">
    <meta name="theme-color" content="#065f46">
    @if (! empty($page['structured_data']))
        <script type="application/ld+json">{!! json_encode($page['structured_data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endif
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="stylesheet" href="{{ asset('css/public.css') }}">
</head>
<body>
    @if (! empty($page['preview']))
        <div class="preview-bar" role="status">Preview of an unpublished version. This page is not visible to the public.</div>
    @endif
    <a class="skip-link" href="#main">Skip to content</a>

    @include('public.partials.header')
    @include('public.partials.banner')

    <main id="main" tabindex="-1">
        @yield('content')
    </main>

    @include('public.partials.footer')

    @if (empty($page['preview']))
        <div class="consent-banner" role="dialog" aria-label="Analytics consent" data-consent-banner hidden>
            <p class="consent-banner__copy">
                We use necessary cookies to run this site. With your permission we also measure page views and
                campaign performance so we can improve the website. Analytics never runs before you accept, and you
                can change your choice at any time. Read the <a href="{{ url('/privacy') }}">privacy notice</a>.
            </p>
            <div class="consent-banner__actions">
                <button type="button" class="button button--primary button--small" data-consent-accept>Accept analytics</button>
                <button type="button" class="button button--ghost button--small" data-consent-necessary>Necessary only</button>
            </div>
        </div>
    @endif

    @if (empty($page['preview']))
        <script>
            (function () {
                if (!window.fetch || !window.localStorage) return;
                var KEY = 'brathamatu.tracking';
                var UTM_KEY = 'brathamatu.utm';
                var POLICY = '2026-11';
                var banner = document.querySelector('[data-consent-banner]');
                var reopen = document.querySelector('[data-consent-reopen]');
                var prefs = readPrefs();
                var sessionId = ensureSession();

                function readPrefs() {
                    try { return JSON.parse(window.localStorage.getItem(KEY) || 'null'); } catch (error) { return null; }
                }

                function writePrefs(value) {
                    try { window.localStorage.setItem(KEY, JSON.stringify(value)); } catch (error) {}
                    prefs = value;
                }

                function ensureSession() {
                    try {
                        var existing = window.sessionStorage.getItem('brathamatu.sid');
                        if (existing) return existing;
                        var generated = (window.crypto && window.crypto.randomUUID)
                            ? window.crypto.randomUUID()
                            : 'sid-' + Date.now() + '-' + Math.random().toString(36).slice(2);
                        window.sessionStorage.setItem('brathamatu.sid', generated);
                        return generated;
                    } catch (error) {
                        return null;
                    }
                }

                function utm() {
                    var keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
                    var stored = {};
                    try { stored = JSON.parse(window.sessionStorage.getItem(UTM_KEY) || '{}') || {}; } catch (error) {}
                    var query = new URLSearchParams(window.location.search);
                    var merged = {};
                    keys.forEach(function (key) {
                        var value = query.get(key);
                        if (value) { merged[key] = value; } else if (stored[key]) { merged[key] = stored[key]; }
                    });
                    try { window.sessionStorage.setItem(UTM_KEY, JSON.stringify(merged)); } catch (error) {}
                    return merged;
                }

                function device() {
                    var width = window.innerWidth;
                    return width < 768 ? 'mobile' : (width < 1024 ? 'tablet' : 'desktop');
                }

                function buildEvent(name, extra) {
                    var event = {
                        name: name,
                        path: window.location.pathname,
                        referrer: document.referrer || null,
                        device: device(),
                        utm: utm(),
                        occurred_at: new Date().toISOString()
                    };
                    if (extra) {
                        Object.keys(extra).forEach(function (key) { event[key] = extra[key]; });
                    }
                    return event;
                }

                function send(events) {
                    if (!prefs || !prefs.analytics || !sessionId || !events.length) return;
                    try {
                        window.fetch('/api/v1/public/analytics/events', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ session_id: sessionId, events: events }),
                            keepalive: true
                        }).catch(function () {});
                    } catch (error) {}
                }

                function recordConsent(analytics) {
                    writePrefs({ analytics: !!analytics, marketing: false, policy_version: POLICY, decided_at: new Date().toISOString() });
                    if (banner) banner.hidden = true;
                    if (reopen) reopen.hidden = false;
                    if (sessionId) {
                        try {
                            window.fetch('/api/v1/public/analytics/consent', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ session_id: sessionId, analytics: !!analytics, marketing: false, policy_version: POLICY })
                            }).catch(function () {});
                        } catch (error) {}
                    }
                    if (analytics) send([buildEvent('page_view')]);
                }

                if (!prefs) {
                    if (banner) banner.hidden = false;
                } else {
                    if (reopen) reopen.hidden = false;
                    send([buildEvent('page_view')]);
                }

                if (banner) {
                    var accept = banner.querySelector('[data-consent-accept]');
                    var necessary = banner.querySelector('[data-consent-necessary]');
                    if (accept) accept.addEventListener('click', function () { recordConsent(true); });
                    if (necessary) necessary.addEventListener('click', function () { recordConsent(false); });
                }

                if (reopen) {
                    reopen.addEventListener('click', function () {
                        if (banner) banner.hidden = false;
                    });
                }

                document.addEventListener('click', function (event) {
                    var link = event.target && event.target.closest ? event.target.closest('a') : null;
                    if (!link) return;
                    var href = link.getAttribute('href') || '';
                    var label = (link.getAttribute('data-analytics-cta') || link.textContent || '').trim().slice(0, 120);
                    if (href.indexOf('tel:') === 0) {
                        send([buildEvent('phone_click', { payload: { label: label } })]);
                    } else if (href.indexOf('wa.me') !== -1 || href.indexOf('whatsapp.com') !== -1) {
                        send([buildEvent('whatsapp_click', { payload: { label: label } })]);
                    } else if (link.hasAttribute('data-analytics-cta')) {
                        send([buildEvent('cta_click', { payload: { label: label, href: href.slice(0, 500) } })]);
                    }
                }, true);
            })();
        </script>
    @endif

    @if (empty($page['preview']))
        <script>
            (function () {
                if (!('PerformanceObserver' in window) || !navigator.sendBeacon) return;
                var samples = { lcp: null, cls: null, inp: null, ttfb: null };
                try {
                    new PerformanceObserver(function (list) {
                        var entries = list.getEntries();
                        samples.lcp = entries.length ? entries[entries.length - 1].startTime : samples.lcp;
                    }).observe({ type: 'largest-contentful-paint', buffered: true });
                } catch (error) {}
                try {
                    new PerformanceObserver(function (list) {
                        list.getEntries().forEach(function (entry) {
                            if (!entry.hadRecentInput) samples.cls = (samples.cls || 0) + entry.value;
                        });
                    }).observe({ type: 'layout-shift', buffered: true });
                } catch (error) {}
                try {
                    new PerformanceObserver(function (list) {
                        list.getEntries().forEach(function (entry) {
                            samples.inp = Math.max(samples.inp || 0, entry.duration);
                        });
                    }).observe({ type: 'event', buffered: true, durationThreshold: 40 });
                } catch (error) {}
                try {
                    var navigation = performance.getEntriesByType('navigation')[0];
                    if (navigation) samples.ttfb = navigation.responseStart;
                } catch (error) {}
                var sent = false;
                function send() {
                    if (sent) return;
                    sent = true;
                    var width = window.innerWidth;
                    var payload = {
                        url: window.location.href,
                        device: width < 768 ? 'mobile' : (width < 1024 ? 'tablet' : 'desktop'),
                        lcp: samples.lcp ? Math.round(samples.lcp) : null,
                        cls: samples.cls != null ? Math.round(samples.cls * 1000) / 1000 : null,
                        inp: samples.inp ? Math.round(samples.inp) : null,
                        ttfb: samples.ttfb ? Math.round(samples.ttfb) : null
                    };
                    try {
                        navigator.sendBeacon('{{ url('/api/v1/public/web-vitals') }}', new Blob([JSON.stringify(payload)], { type: 'application/json' }));
                    } catch (error) {}
                }
                window.addEventListener('pagehide', send);
                document.addEventListener('visibilitychange', function () {
                    if (document.visibilityState === 'hidden') send();
                });
            })();
        </script>
    @endif
</body>
</html>
