@props(['gallery'])
@if(\App\Services\PhotoStorage::isLocal() && $gallery->business_id === config('photohub.business_id'))
<div class="content-card mb-3">
    <h5>Cloud synchronization <a class="btn btn-sm btn-light" href="{{ route('sync.index') }}">Sync dashboard</a></h5>
    <p>Status: <strong>{{ ['local_only'=>'Local only','queued'=>'Waiting for Internet / queued','uploading'=>'Uploading','synced'=>'Online','failed'=>'Sync failed — Retry','deleted_cloud'=>'Removed from cloud'][$gallery->cloud_status] ?? $gallery->cloud_status }}</strong>
    · Cloud Gallery ID: {{ $gallery->cloud_gallery_id ?? '—' }} · Last synced: {{ $gallery->last_synced_at ?? 'Never' }}</p>
    <p>Customer selection: {{ $gallery->submitted_selection_count ?? 0 }} photos · Last synchronized: {{ $gallery->selection_synced_at ?? 'Never' }}</p>
    @foreach(['Customer Link' => $gallery->cloud_url, 'Final Photos' => $gallery->cloud_final_url] as $label => $link)
        @if($link)<div class="input-group mb-2"><span class="input-group-text">{{ $label }}</span><input class="form-control" readonly value="{{ $link }}" aria-label="{{ $label }}"><a class="btn btn-outline-primary" href="{{ $link }}" target="_blank" rel="noopener">Open</a><button type="button" class="btn btn-outline-primary" onclick="const i=this.parentElement.querySelector('input');i.select();if(navigator.clipboard){navigator.clipboard.writeText(i.value)}else{document.execCommand('copy')}">Copy Link</button></div>@endif
    @endforeach
    @if($gallery->sync_error)<p class="text-danger">{{ $gallery->sync_error }}</p>@endif
    @if(auth()->user()->hasPermission('galleries.manage'))
    <div class="d-flex flex-wrap gap-2">
    @foreach(['publish'=>'Publish Online','selections'=>'Sync Customer Selection','finals'=>'Publish Final Photos','retry'=>'Retry Failed'] as $action => $label)
        <form method="POST" action="{{ route('sync.action',$gallery) }}">@csrf<input type="hidden" name="action" value="{{ $action }}"><button class="btn btn-outline-primary">{{ $label }}</button></form>
    @endforeach
        <form method="POST" action="{{ route('sync.action',$gallery) }}" onsubmit="return confirm('Remove this gallery and its files from the cloud? Local photos will remain on this computer.')">@csrf<input type="hidden" name="action" value="remove"><input type="hidden" name="confirm_remove" value="1"><button class="btn btn-outline-danger">Remove From Cloud</button></form>
    </div>
    @endif
</div>
@endif
