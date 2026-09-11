@extends('layouts.app')
@section('title',$gallery->name.' workflow')
@section('content')
<x-gallery-sync :gallery="$gallery" />
<x-gallery-expiry :gallery="$gallery" />
<x-page-header :title="$gallery->name" subtitle="Share photos with your client."><a href="{{ $gallery->type === 'final_delivery' ? '#final-photos' : route('galleries.show',$gallery) }}" class="btn btn-light">Manage photos</a></x-page-header>
@php($selectionUrl=$selectionToken?$access->url($selectionToken):null)
@php($finalUrl=$finalToken?$access->url($finalToken):null)
@unless(\App\Services\PhotoStorage::isLocal())
<div class="content-card mb-3 p-3" style="min-height:0">
    <h2 class="h5 mb-2">Share finished photos</h2>
    @if($finalToken)
        <x-gallery-share :gallery="$gallery" :url="$finalUrl" mode="delivery" compact />
        <details class="mt-2 small">
            <summary>View download link</summary>
            <label for="final-link" class="form-label mt-3">Client download link</label>
            <input id="final-link" class="form-control" readonly value="{{ $finalUrl }}">
            <small class="d-block text-muted mt-2">Created {{ $finalToken->generated_at->format('j F Y') }} &middot; Available until {{ $finalToken->expires_at->copy()->min($gallery->expires_at)->format('j F Y') }}</small>
        </details>
    @else
        <p class="small text-muted mb-2">Start by uploading finished photos, then create an invitation to share.</p>
        <a class="btn btn-primary" href="#final-photos">Add finished photos</a>
    @endif
</div>
<details class="content-card mb-4" style="min-height:0" @if($selectionToken || $selected->isNotEmpty()) open @endif>
    <summary class="fw-semibold">Ask your client to choose photos <span class="small text-muted fw-normal">(optional)</span></summary>
    <p class="text-muted mt-3">Use this when your client needs to choose photos before you edit them.</p>
    @if($selectionToken)
        <x-gallery-share :gallery="$gallery" :url="$selectionUrl" mode="selection" />
        <details class="mt-3">
            <summary>Link settings</summary>
            <label for="selection-link" class="form-label mt-3">Client selection link</label>
            <input id="selection-link" class="form-control" readonly value="{{ $selectionUrl }}">
            <small class="d-block text-muted mt-2">Created {{ $selectionToken->generated_at->format('j F Y') }} &middot; Available until {{ $selectionToken->expires_at->copy()->min($gallery->expires_at)->format('j F Y') }}</small>
            <div class="d-flex flex-wrap gap-3 mt-3 align-items-end">
                <form data-feedback-form data-loading="Updating link..." method="POST" action="{{ route('galleries.links.update',$selectionToken) }}">
                    @csrf @method('PATCH')<input type="hidden" name="action" value="extend">
                    <label for="selection-expiry" class="form-label small">New expiry date</label>
                    <div class="d-flex flex-wrap gap-2"><input id="selection-expiry" type="date" name="expires_at" min="{{ today()->addDay()->format('Y-m-d') }}" class="form-control form-control-sm w-auto" required><button class="btn btn-sm btn-outline-primary">Save expiry</button></div>
                </form>
                <form data-feedback-form data-loading="Creating customer link..." method="POST" action="{{ route('galleries.links.generate',$gallery) }}">
                    @csrf<input type="hidden" name="purpose" value="selection"><input type="hidden" name="revoke_previous" value="1"><button class="btn btn-sm btn-outline-secondary">Replace link</button>
                </form>
                <form data-feedback-form data-loading="Updating link..." method="POST" action="{{ route('galleries.links.update',$selectionToken) }}">
                    @csrf @method('PATCH')<input type="hidden" name="action" value="revoke"><button class="btn btn-sm btn-outline-danger">Disable link</button>
                </form>
            </div>
            <small class="d-block text-muted mt-2">Replacing or disabling a link stops the old link from working.</small>
        </details>
    @else
        <form data-feedback-form data-loading="Creating customer link..." method="POST" action="{{ route('galleries.links.generate',$gallery) }}">
            @csrf<input type="hidden" name="purpose" value="selection"><input type="hidden" name="revoke_previous" value="1"><button class="btn btn-outline-primary">Create selection invitation</button>
            <small class="d-block text-muted mt-2">Available until the gallery expires.</small>
        </form>
    @endif
</details>
@endunless
<div id="final-photos" class="content-card mb-4" style="min-height:0;scroll-margin-top:24px"><h4>Finished photos</h4><p>{{ \App\Services\PhotoStorage::isLocal() ? 'Add your edited photos here. Use Online Sharing above when you are ready to send them to your client.' : 'Upload the photos you want the client to download. Client selection is optional.' }}</p>@if($selected->isNotEmpty())<p>Selected: {{ $selected->count() }} · Finals uploaded: {{ $finalPhotos->count() }} · Matched: {{ $finalPhotos->whereNotNull('proof_photo_id')->count() }} · Missing: {{ max(0,$selected->count()-$finalPhotos->whereNotNull('proof_photo_id')->count()) }}</p>@else<p>{{ $finalPhotos->count() }} finished photos uploaded</p>@endif<form data-photo-upload enctype="multipart/form-data" method="POST" action="{{ route('galleries.finals.upload',$gallery) }}" class="row g-2">@csrf<div class="col-md-9"><input type="file" name="finals[]" multiple accept="image/jpeg,image/png,image/webp" class="form-control" required>@if($selected->isNotEmpty())<small>Matching uses the original filename automatically.</small>@endif</div><div class="col-md-3"><button class="btn btn-primary w-100">{{ \App\Services\PhotoStorage::isLocal() ? 'Save finished photos locally' : 'Upload finished photos' }}</button></div></form><div class="row g-2 my-3">@foreach($finalPhotos as $photo)<div class="col-6 col-md-3"><div class="card h-100"><img class="card-img-top gallery-admin-preview" alt="{{ $photo->filename }}" src="{{ route('galleries.finals.preview',[$gallery,$photo]) }}"><div class="card-body"><small>{{ $photo->filename }}</small>@if($selected->isNotEmpty())<span class="badge text-bg-{{ $photo->proof_photo_id?'success':'warning' }}">{{ $photo->proof_photo_id?'Matched':'Unmatched' }}</span>@endif</div></div></div>@endforeach</div>@if($finalPhotos->isNotEmpty() && !\App\Services\PhotoStorage::isLocal())<form data-feedback-form data-loading="Preparing download link..." method="POST" action="{{ route('galleries.finals.publish',$gallery) }}">@csrf @if($finalPhotos->whereNotNull('proof_photo_id')->count()<$selected->count())<label class="d-block mb-2"><input type="checkbox" name="confirm_incomplete" value="1" required> I understand some selected photos are missing and intentionally want to publish fewer.</label>@endif<button class="btn btn-success btn-lg">{{ $finalToken ? 'Update download link' : 'Create download link' }}</button></form>@endif</div>
@if($selectionToken || $selected->isNotEmpty())<div class="content-card mb-4"><div class="d-flex justify-content-between flex-wrap gap-2"><div><h4>Customer selection</h4><p class="mb-0">{{ $selected->count() }} selected of {{ $gallery->photos()->where('is_proof',true)->count() }} proofs · <strong>{{ str($gallery->status)->replace('_',' ')->title() }}</strong></p></div><div class="d-flex gap-2">@if($selected->isNotEmpty())<a class="btn btn-dark" href="{{ route('galleries.selected.zip',$gallery) }}">Download Selected Originals</a>@endif @if($gallery->selection_completed_at)<form method="POST" action="{{ route('galleries.selection.reopen',$gallery) }}">@csrf<button class="btn btn-outline-danger">Reopen Selection</button></form><form method="POST" action="{{ route('galleries.editing',$gallery) }}">@csrf<button class="btn btn-primary">Start Editing</button></form>@endif</div></div><div class="row g-2 mt-3">@forelse($selected as $photo)<div class="col-6 col-md-3"><div class="card border-danger h-100"><img class="card-img-top gallery-admin-preview" alt="{{ $photo->filename }}" src="{{ route('photos.preview',[$gallery,$photo]) }}"><div class="card-body"><small>{{ $photo->filename }}</small><a class="btn btn-sm btn-outline-dark d-block mt-2" href="{{ route('galleries.selected.download',[$gallery,$photo]) }}">Original</a><form data-photo-upload enctype="multipart/form-data" method="POST" action="{{ route('galleries.finals.upload',$gallery) }}" class="mt-2">@csrf<input type="hidden" name="proof_photo_id" value="{{ $photo->id }}"><input type="file" name="finals[]" accept="image/*" class="form-control form-control-sm" required><button class="btn btn-sm btn-outline-primary w-100 mt-1">Replace with final</button></form></div></div></div>@empty<p class="text-muted">No photos selected yet.</p>@endforelse</div></div>@endif
@endsection

@push('scripts')<script src="{{ asset('js/photo-upload.js') }}"></script>@endpush
