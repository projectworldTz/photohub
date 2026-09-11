@props(['gallery', 'dashboard' => false])
@if(\App\Services\PhotoStorage::isLocal())
@php($studioConnection = app(\App\Services\CloudStudioService::class)->connection($gallery->business))
<section class="content-card cloud-sync-card mb-3" data-online-sharing data-studio-ready="{{ $studioConnection->api_token ? 'true' : 'false' }}">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0">@if($dashboard)<a href="{{ route('galleries.show',$gallery) }}">{{ $gallery->name }}</a>@else Online Sharing @endif</h2>
        @unless($dashboard)<a class="small" href="{{ route('online.index') }}">All online galleries</a>@endunless
    </div>
    @if($dashboard)<p class="small text-muted mt-2 mb-0">{{ $gallery->photos_count }} local photos &middot; {{ $gallery->cloud_photos_count }} previews online &middot; {{ $gallery->final_photos_count }} finished photos</p>@endif
    <p class="small text-muted mt-2">Your studio connects automatically the first time you share. @if(auth()->user()->hasPermission('settings.manage'))<a href="{{ route('settings.index') }}#cloud-connection">Cloud Connection / Connect Studio</a>@endif</p>
    @unless(config('photohub.cloud_enabled'))<p class="small text-muted mt-2">Online sharing is disabled. Your local galleries remain available.</p>@endunless
    <div class="row g-3 mt-0">
    @foreach(['preview' => ['Preview Gallery', 'cloud_url', 'previews'], 'final' => ['Final Delivery', 'cloud_final_url', 'finals']] as $kind => [$title, $urlField, $action])
        @php($link = $gallery->{$urlField})
        @php($state = $gallery->{$kind.'_share_status'} ?? 'offline_only')
        <div class="col-md-6">
            <h3 class="h6 mb-1">{{ $title }}</h3>
            <p class="small text-muted mb-2">
                @if($link){{ $gallery->isExpired() ? 'Link expired. Extend the gallery and update it online.' : 'Online' }}@else Not shared online yet.@endif
                @if($state === 'uploading')<span class="d-block">Upload started. Keep this page open, or use the button below to resume.</span>@endif
            </p>
            <div class="d-flex flex-wrap gap-2">
                @if($link)
                    <button type="button" class="btn btn-sm btn-outline-primary" data-copy-cloud-link="{{ $link }}">{{ $kind === 'preview' ? 'Copy Selection Link' : 'Copy Download Link' }}</button>
                    <a class="btn btn-sm btn-light" href="{{ $link }}" target="_blank" rel="noopener noreferrer">Preview<span class="visually-hidden"> {{ $title }}</span></a>
                @endif
                @if(auth()->user()->hasPermission('galleries.manage'))
                    <form method="POST" action="{{ route('online.start', [$gallery, $action]) }}" data-sharing-form>@csrf<button class="btn btn-sm {{ $kind === 'preview' && !$link ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $kind === 'preview' ? ($link ? 'Update Online Gallery' : 'Create Customer Link') : 'Upload Finished Photos' }}</button></form>
                @endif
            </div>
            @if($gallery->{$kind.'_share_error'})<p class="small text-warning-emphasis mt-2 mb-0">Last {{ $kind === 'preview' ? 'preview' : 'delivery' }} attempt: {{ $gallery->{$kind.'_share_error'} }}</p>@endif
            @php($failedPhotos = app(\App\Services\OnlineGalleryService::class)->photos($gallery, $kind === 'preview' ? 'publish' : 'finals')->where('sync_status', 'failed')->get(['filename', 'sync_error']))
            @if($failedPhotos->isNotEmpty())
                <details class="small mt-2"><summary>{{ $failedPhotos->count() }} photos to retry</summary><ul class="mt-2">@foreach($failedPhotos as $photo)<li class="text-break">{{ $photo->filename }}: {{ $photo->sync_error }}</li>@endforeach</ul><p>Use the upload button to retry. Photos already uploaded are skipped.</p></details>
            @endif
        </div>
    @endforeach
    </div>
    @if($gallery->cloud_url)
        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
            @if(auth()->user()->hasPermission('galleries.manage'))<form method="POST" action="{{ route('online.start', [$gallery, 'selections']) }}" data-sharing-form>@csrf<button class="btn btn-sm btn-outline-secondary">Get Client Selections</button></form>@endif
            <span class="small text-muted">{{ $gallery->submitted_selection_count ?? 0 }} selected &middot; Last checked: {{ $gallery->selection_synced_at?->format('d M Y, H:i') ?? 'Never' }}</span>
        </div>
        @if($gallery->selection_share_error)<p class="small text-warning-emphasis mt-2">{{ $gallery->selection_share_error }}</p>@endif
    @endif
    <p class="small mt-2 mb-0" role="status" aria-live="polite" data-sharing-status></p>
    <a class="btn btn-sm btn-light mt-2" data-sharing-result hidden target="_blank" rel="noopener noreferrer">Open client gallery</a>
    <input class="form-control form-control-sm mt-2" data-cloud-copy-fallback readonly hidden aria-label="Client link to copy">
    <details class="small mt-3">
        <summary>Sharing details</summary>
        <p class="text-muted mt-2">Internet is required only to create, update or remove online galleries and get client selections. Your local photos and work remain safe on this computer.</p>
        <p>Expires: {{ $gallery->expires_at?->format('d M Y') }} &middot; Previews last shared: {{ $gallery->preview_shared_at?->format('d M Y, H:i') ?? 'Never' }} &middot; Finals last shared: {{ $gallery->final_shared_at?->format('d M Y, H:i') ?? 'Never' }}</p>
        @if($gallery->cloud_gallery_id && auth()->user()->hasPermission('galleries.manage'))<form method="POST" action="{{ route('online.remove', $gallery) }}" data-sharing-form data-confirm="Remove the online gallery and its links? Your local photos will remain on this computer.">@csrf @method('DELETE')<input type="hidden" name="confirm_remove" value="1"><button class="btn btn-sm btn-outline-danger">Remove Online Gallery</button></form>@endif
    </details>
</section>
@once
@if(session('sharing_operation'))<form method="POST" action="{{ session('sharing_operation.continue_url') }}" class="mb-3">@csrf<button class="btn btn-outline-primary">Continue sharing upload</button></form>@endif
@push('scripts')<script src="{{ asset('js/online-sharing.js') }}"></script>@endpush
@endonce
@endif
