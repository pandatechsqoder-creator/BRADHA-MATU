@php
    $heading = $content['heading'] ?? null;
    $intro = $content['intro'] ?? null;
    $steps = $content['steps'] ?? [];
    $headingId = $heading ? \Illuminate\Support\Str::slug($heading) : null;
@endphp
@if ($heading || ! empty($steps))
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
            <ol class="process">
                @foreach ($steps as $index => $step)
                    <li class="process-step">
                        <span class="process-number" aria-hidden="true">{{ $index + 1 }}</span>
                        <h3>{{ $step['title'] ?? '' }}</h3>
                        @if (! empty($step['body']))
                            <p>{{ $step['body'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    </section>
@endif
