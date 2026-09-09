@extends('layouts.app')
@section('title', $gallery->name)
@section('content')
<div class="container py-5">
    <div class="text-center mb-5">
        <span class="eyebrow">{{ $gallery->business->name }}</span>
        <h1>{{ $gallery->name }}</h1><p class="container small mt-2">This gallery will be available until {{ $gallery->expires_at?->format('d F Y, H:i') }}.</p>
        <p>{{ $gallery->description }}</p>
        @if($gallery->payment_required)<span class="badge text-bg-warning">Paid downloads</span>@endif
    </div>
    <form method="POST" id="photo-order" action="{{ route('public.gallery.order', $gallery->code) }}">
        @csrf
        <div class="row g-3">
            @foreach($photos as $p)
                @php($canDownload = $gallery->downloads_enabled && $p->is_downloadable && (!$gallery->payment_required || $paidPhotoIds->contains($p->id)))
                <div class="col-6 col-md-4">
                    <article class="content-card h-100 p-2 proof-card {{ $selectedPhotoIds->contains($p->id)?'border border-danger border-3':'' }}">
                        <img data-download="{{ $canDownload ? route('public.gallery.download', [$gallery->code, $p]) : '' }}" class="w-100 rounded gallery-image" role="button" loading="lazy" src="{{ route('public.gallery.photo', [$gallery->code, $p]) }}" alt="{{ $p->filename }}" data-bs-toggle="modal" data-bs-target="#lightbox">
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <button type="button" class="btn btn-light btn-sm action" data-url="{{ route('public.gallery.favorite', [$gallery->code, $p]) }}">♡ Favorite</button>
                            @if($gallery->type === 'proof')<button type="button" class="btn btn-sm action proof-action {{ $selectedPhotoIds->contains($p->id)?'btn-danger':'btn-outline-danger' }}" data-url="{{ route('public.gallery.select', [$gallery->code, $p]) }}">{{ $selectedPhotoIds->contains($p->id)?'✓ SELECTED':'Select photo' }}</button>@endif
                            @if((float)$gallery->photo_price > 0)<label class="btn btn-outline-primary btn-sm"><input class="photo-choice" type="checkbox" name="photos[]" value="{{ $p->id }}"> Buy</label>
                            @elseif($canDownload)<label class="btn btn-outline-secondary btn-sm"><input class="photo-choice" type="checkbox" value="{{ $p->id }}"> ZIP</label>@endif
                            @if($canDownload)<a class="btn btn-success btn-sm" href="{{ route('public.gallery.download', [$gallery->code, $p]) }}">Download Photo</a>@endif
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
        @if((float)$gallery->photo_price > 0)
            <div class="sticky-bottom bg-white border rounded p-3 mt-4 d-flex justify-content-between align-items-center">
                <span>{{ $gallery->business->currency }} {{ number_format((float)$gallery->photo_price, 2) }} per photo · 10% off 5+, 15% off 10+</span>
                <button class="btn btn-primary">Order selected photos</button>
            </div>
        @endif
    </form>
    @if($gallery->type === 'proof')
        @if($gallery->selection_completed_at)<div class="alert alert-success mt-3">Selection submitted {{ $gallery->selection_completed_at->diffForHumans() }}. You can still add or remove photos, then submit the updated selection.</div>@endif
        <form method="POST" action="{{ route('public.gallery.selection.complete',$gallery->code) }}" class="mt-3">@csrf<button id="complete-selection" class="btn btn-dark" @disabled($selectedPhotoIds->isEmpty()) onclick="return confirm('Submit these selected photos to the photographer?')">{{ $gallery->selection_completed_at?'Resubmit selection':'Submit selection' }} (<span id="selection-count">{{ $selectedPhotoIds->count() }}</span>)</button><small id="selection-help" class="d-block text-muted mt-1">{{ $selectedPhotoIds->isEmpty()?'Select at least one photo first.':'Red photos are selected. You may update and resubmit later.' }}</small></form>
    @endif
    @if($gallery->downloads_enabled)
        <div class="d-flex gap-2 mt-3"><form method="POST" action="{{ route('public.gallery.zip', $gallery->code) }}" onsubmit="return copySelected(this)">@csrf<div class="zip-inputs"></div><button class="btn btn-outline-dark">Download selected as ZIP</button></form><form method="POST" action="{{ route('public.gallery.zip-all',$gallery->code) }}">@csrf<button class="btn btn-dark">Download All as ZIP</button></form></div>
    @endif
    <div class="mt-4">{{ $photos->links() }}</div>
</div>
<div class="modal fade" id="lightbox" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content bg-dark"><div class="modal-header"><a id="lightbox-download" class="btn btn-light" hidden>Download Photo</a><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body text-center position-relative"><button class="btn btn-light position-absolute start-0 top-50" id="previous" type="button">‹</button><img id="lightbox-image" class="img-fluid" style="max-height:85vh" alt="Gallery preview"><button class="btn btn-light position-absolute end-0 top-50" id="next" type="button">›</button></div></div></div></div>
@endsection
@push('scripts')
<script>
document.querySelectorAll('.action').forEach(b => b.onclick = async () => {
    const r = await fetch(b.dataset.url, {method:'POST', headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'}});
    const j = await r.json(); if (!r.ok) return alert(j.message || 'Action failed');
    if (b.classList.contains('proof-action')) { b.classList.toggle('btn-danger',j.selected); b.classList.toggle('btn-outline-danger',!j.selected); b.textContent=j.selected?'✓ SELECTED':'Select photo'; b.closest('.proof-card').classList.toggle('border',j.selected); b.closest('.proof-card').classList.toggle('border-danger',j.selected); b.closest('.proof-card').classList.toggle('border-3',j.selected); const selected=document.querySelectorAll('.proof-action.btn-danger').length; document.getElementById('selection-count').textContent=selected; document.getElementById('complete-selection').disabled=selected===0; document.getElementById('complete-selection').childNodes[0].textContent='Submit updated selection ('; document.getElementById('selection-help').textContent=selected===0?'Select at least one photo first.':'Red photos are selected. You may update and resubmit later.'; } else b.classList.toggle('btn-success');
});
function copySelected(form) {
    const box = form.querySelector('.zip-inputs'); box.innerHTML = '';
    const selected=document.querySelectorAll('.photo-choice:checked'); if(!selected.length){alert('Select at least one photo.');return false} selected.forEach(c => { const i=document.createElement('input'); i.type='hidden'; i.name='photos[]'; i.value=c.value; box.appendChild(i); }); return true;
}
function showImage(){const img=galleryImages[current];lightbox.src=img.src;const link=document.getElementById('lightbox-download');link.hidden=!img.dataset.download;link.href=img.dataset.download||'#'}
const galleryImages=[...document.querySelectorAll('.gallery-image')]; let current=0; const lightbox=document.getElementById('lightbox-image'); galleryImages.forEach((img,index)=>img.addEventListener('click',()=>{current=index;showImage()})); document.getElementById('previous').onclick=()=>{current=(current-1+galleryImages.length)%galleryImages.length;showImage()}; document.getElementById('next').onclick=()=>{current=(current+1)%galleryImages.length;showImage()};
</script>
@endpush
