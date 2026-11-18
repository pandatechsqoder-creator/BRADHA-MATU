@php
    $heading = $content['heading'] ?? null;
    $updated = $content['updated'] ?? null;
    $intro = $content['intro'] ?? [];
    $blocks = $content['blocks'] ?? [];
@endphp
@if ($heading || ! empty($intro) || ! empty($blocks))
    <section class="section legal-document">
        <div class="container prose">
            @if ($heading)
                <h1>{{ $heading }}</h1>
            @endif
            @if ($updated)
                <p class="legal-updated">Last updated: {{ $updated }}</p>
            @endif
            @foreach ($intro as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
            @foreach ($blocks as $block)
                <h2>{{ $block['heading'] }}</h2>
                @foreach (($block['paragraphs'] ?? []) as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
                @if (! empty($block['bullets']))
                    <ul class="check-list">
                        @foreach ($block['bullets'] as $bullet)
                            <li>{{ $bullet }}</li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        </div>
    </section>
@endif
