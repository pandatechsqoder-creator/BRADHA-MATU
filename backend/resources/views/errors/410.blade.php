@extends('public.layout')

@section('meta_title', 'Page removed | '.$chrome['brand']['name'])
@section('meta_description', 'This page is no longer available.')
@section('meta_robots', 'noindex, nofollow')

@section('content')
    <section class="section error-page">
        <div class="container error-inner">
            <p class="eyebrow">410</p>
            <h1>This page has been removed</h1>
            <p class="lead">The page is no longer available. The rest of the website is still here.</p>
            <div class="button-row">
                <a class="button button--primary" href="{{ url('/') }}">Go to the homepage</a>
                <a class="button button--secondary" href="{{ url('/contact') }}">Contact us</a>
            </div>
        </div>
    </section>
@endsection
