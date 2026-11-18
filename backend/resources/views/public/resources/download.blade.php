@extends('public.layout')

@section('content')
    @include('public.partials.breadcrumbs', ['items' => $page['breadcrumbs']])

    <section class="section">
        <div class="container narrow">
            <h1>{{ $resource->title }}</h1>
            @if ($resource->description)
                <p class="article-lede">{{ $resource->description }}</p>
            @endif

            @if ($resource->asset === null)
                <p>This download is not available right now. Please <a href="{{ url('/contact') }}">contact the team</a> and we will send it to you.</p>
            @else
                <form class="download-form" method="post" action="{{ route('resources.download.request', $resource->slug) }}">
                    @csrf
                    @if ($resource->requires_consent)
                        <label class="consent-field">
                            <input type="checkbox" name="consent" value="accepted" required>
                            <span>I agree to receive this download and understand it is general guidance, not advice for my specific business.</span>
                        </label>
                    @endif
                    <button class="button button--primary" type="submit">
                        {{ $resource->requires_consent ? 'Agree and download' : 'Download' }}
                    </button>
                </form>
            @endif

            <p class="download-meta">Downloaded {{ $resource->download_count }} {{ \Illuminate\Support\Str::plural('time', $resource->download_count) }}.</p>
        </div>
    </section>
@endsection
