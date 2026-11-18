@extends('public.layout')

@section('content')
    @unless ($page['is_home'])
        @include('public.partials.breadcrumbs', ['items' => $page['breadcrumbs']])
    @endunless

    @foreach ($page['sections'] as $section)
        @include('public.sections.'.$section['type'], [
            'content' => $section['content'],
            'settings' => $section['settings'],
            'contact' => $page['contact'],
            'brand' => $page['brand'],
        ])
    @endforeach
@endsection
