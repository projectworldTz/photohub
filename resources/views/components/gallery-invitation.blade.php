@props(['gallery', 'cover' => null, 'mode' => 'selection', 'count' => null])
<header class="gallery-invitation {{ $cover ? 'has-cover' : '' }}">
    @if($cover)<img class="invitation-cover" src="{{ $cover }}" alt="" fetchpriority="high">@endif
    <div class="invitation-shade"></div>
    <div class="invitation-studio">
        @if($gallery->business->logo_path)<img src="{{ route('public.business.logo', $gallery->business->slug) }}" alt="{{ $gallery->business->name }} logo">@else<span class="studio-monogram" aria-hidden="true">{{ mb_substr($gallery->business->name, 0, 1) }}</span>@endif
        <span>{{ $gallery->business->name }}</span>
    </div>
    <div class="invitation-content">
        <p class="invitation-eyebrow">{{ $mode === 'delivery' ? 'Your Photos Are Ready' : 'An invitation to your gallery' }}</p>
        <h1>{{ $gallery->name }}</h1>
        @if($gallery->event || $gallery->event_date)<p class="invitation-event">{{ $gallery->event }} @if($gallery->event && $gallery->event_date)<span aria-hidden="true"> / </span>@endif {{ $gallery->event_date?->format('d F Y') }}</p>@endif
        <div class="invitation-rule"></div>
        <p class="invitation-note">{{ $gallery->description ?: ($mode === 'delivery' ? 'Beautifully finished. Yours to keep. Relive the moments and download your photographs below.' : 'Your moments, thoughtfully captured. Explore your gallery and choose the photographs you love most.') }}</p>
        <a class="invitation-button" href="{{ $mode === 'locked' ? '#unlock-gallery' : '#client-photos' }}">{{ $mode === 'locked' ? 'Open your gallery' : ($mode === 'delivery' ? 'View your photos' : 'Choose your photos') }} <span aria-hidden="true">&darr;</span></a>
        <p class="invitation-details">@if($count !== null){{ $count }} photographs @if($gallery->expires_at)<span aria-hidden="true">&middot;</span>@endif @endif @if($gallery->expires_at)Available until {{ $gallery->expires_at->format('d F Y') }}@endif</p>
    </div>
    <div class="invitation-signature">A collection by {{ $gallery->business->name }}</div>
</header>
