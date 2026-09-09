@props(['gallery'])
@if($gallery->expires_at)
<div class="alert alert-{{ $gallery->isExpired() ? 'danger' : ($gallery->expires_at->lessThanOrEqualTo(now()->addDays(7)) ? 'warning' : 'light') }}">
    <strong>{{ $gallery->expiryLabel() }}</strong>
    <span class="d-block">Gallery expires on: {{ $gallery->expires_at->format('d F Y, H:i') }}</span>
    @if($gallery->isExpired())<small>Client access is disabled. Your files are retained and still count toward storage.</small>@endif
</div>
@endif
