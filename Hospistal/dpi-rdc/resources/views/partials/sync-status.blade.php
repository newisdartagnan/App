@php
    $centralUrl = config('dpi.central_api_url', env('CENTRAL_API_URL', ''));
    $isLocalMode = blank($centralUrl)
        || str_contains((string) $centralUrl, 'localhost')
        || str_contains((string) $centralUrl, '127.0.0.1');
@endphp
<div class="text-right text-xs">
    @if ($isLocalMode)
        <span class="inline-block px-2 py-1 bg-blue-600 rounded text-white">Mode local</span>
    @else
        <span class="inline-block px-2 py-1 bg-amber-600 rounded text-white">Sync central</span>
    @endif
</div>
