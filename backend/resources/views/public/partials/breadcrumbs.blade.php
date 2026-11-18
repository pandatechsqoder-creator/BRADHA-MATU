@if (! empty($items))
    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <div class="container">
            <ol>
                @foreach ($items as $item)
                    <li>
                        @if ($item['url'])
                            <a href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                        @else
                            <span aria-current="page">{{ $item['label'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    </nav>
@endif
