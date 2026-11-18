@extends('public.layout')

@section('meta_title', 'Page not found | '.$chrome['brand']['name'])
@section('meta_description', 'The page you requested could not be found.')
@section('meta_robots', 'noindex, nofollow')

@section('content')
    <section class="section error-page">
        <div class="container error-inner">
            <p class="eyebrow">404</p>
            <h1>Page not found</h1>
            <p class="lead">The page may have moved, or the address may be incorrect. Use the links below to continue.</p>
            <div class="button-row">
                <a class="button button--primary" href="{{ url('/') }}">Go to the homepage</a>
                <a class="button button--secondary" href="{{ url('/stock-taking') }}">Explore stock taking</a>
                <a class="button button--secondary" href="{{ url('/contact') }}">Contact us</a>
            </div>
        </div>
    </section>
@endsection
