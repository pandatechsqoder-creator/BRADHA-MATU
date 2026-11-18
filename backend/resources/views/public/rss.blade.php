<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>{{ $brand['name'] }} — Resources</title>
        <link>{{ url('/resources') }}</link>
        <description>{{ $brand['short_description'] }}</description>
        <language>en</language>
        @foreach ($posts as $post)
            <item>
                <title>{{ $post['title'] }}</title>
                <link>{{ $post['url'] }}</link>
                <guid isPermaLink="true">{{ $post['url'] }}</guid>
                <description>{{ $post['description'] }}</description>
                @if ($post['published_at'])
                    <pubDate>{{ $post['published_at'] }}</pubDate>
                @endif
                @if ($post['author'])
                    <author>{{ $post['author'] }}</author>
                @endif
                @if ($post['category'])
                    <category>{{ $post['category'] }}</category>
                @endif
            </item>
        @endforeach
    </channel>
</rss>
