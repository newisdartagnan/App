@if ($errors->any())
<div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">@foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach</div>
@endif
