@php($extra = trim((string) $attributes->except('id')))
@if ($embedded !== null)<script type="application/json" id="{{ $embeddedId }}">{!! $embedded !!}</script>
@endif<div id="{{ $rootId }}" data-bridge{!! $extra !== '' ? ' '.$extra : '' !!}@if ($body !== null) data-server-rendered="true"@endif>{!! $body ?? '' !!}</div>
