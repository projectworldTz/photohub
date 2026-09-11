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
    <p class="pf-gallery-status" data-gallery-loading data-ready="Your gallery is ready." role="status" aria-live="polite" aria-busy="true"><span class="pf-spinner" aria-hidden="true"></span>Loading your gallery...</p>
    <form method="POST" id="photo-order" action="{{ route('public.gallery.order', $gallery->code) }}">
        @csrf
        <div class="row g-3">
            @foreach($photos as $p)
                @php($canDownload = $gallery->downloads_enabled && $p->is_downloadable && (!$gallery->payment_required || $paidPhotoIds->contains($p->id)))
                <div class="col-6 col-md-4">
                    <article class="content-card h-100 p-2 proof-card {{ $selectedPhotoIds->contains($p->id)?'border border-danger border-3':'' }}">
                        <img data-gallery-image data-download="{{ $canDownload ? route('public.gallery.download', [$gallery->code, $p]) : '' }}" class="w-100 rounded gallery-image pf-image-loading" role="button" loading="lazy" src="{{ route('public.gallery.photo', [$gallery->code, $p]) }}" alt="{{ $p->filename }}" data-bs-toggle="modal" data-bs-target="#lightbox">
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <button type="button" class="btn btn-light btn-sm action" data-url="{{ route('public.gallery.favorite', [$gallery->code, $p]) }}">♡ Favorite</button>
                            @if($gallery->type === 'proof')<button type="button" class="btn btn-sm action proof-action {{ $selectedPhotoIds->contains($p->id)?'btn-danger':'btn-outline-danger' }}" data-url="{{ route('public.gallery.select', [$gallery->code, $p]) }}">{{ $selectedPhotoIds->contains($p->id)?'✓ SELECTED':'Select photo' }}</button>@endif
                            @if((float)$gallery->photo_price > 0)<label class="btn btn-outline-primary btn-sm"><input class="photo-choice" type="checkbox" name="photos[]" value="{{ $p->id }}"> Add to order</label>
                            @elseif($canDownload)<label class="btn btn-outline-secondary btn-sm"><input class="photo-choice" type="checkbox" value="{{ $p->id }}"> Choose</label>@endif
                            @if($canDownload)<a data-download-link class="btn btn-success btn-sm" href="{{ route('public.gallery.download', [$gallery->code, $p]) }}">Download</a>@endif
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
        <form data-feedback-form data-selection-submit data-loading="Submitting your selections..." data-success="Your photo selections were submitted successfully." data-error="We couldn't submit your selections. Please check your connection and try again." method="POST" action="{{ route('public.gallery.selection.complete',$gallery->code) }}" class="mt-3">@csrf<button id="complete-selection" class="btn btn-dark" @disabled($selectedPhotoIds->isEmpty()) onclick="return confirm('Submit these selected photos to the photographer?')">{{ $gallery->selection_completed_at?'Resubmit selection':'Submit selection' }} (<span id="selection-count">{{ $selectedPhotoIds->count() }}</span>)</button><small id="selection-help" class="d-block text-muted mt-1">{{ $selectedPhotoIds->isEmpty()?'Select at least one photo first.':'Red photos are selected. You may update and resubmit later.' }}</small></form>
    @endif
    @if($gallery->downloads_enabled)
        <p class="small text-muted mt-3">Choose photos above to download a few, or download all available photos. Multiple photos download together in a ZIP file.</p><div class="d-flex flex-wrap gap-2 mt-3"><form data-download-form method="POST" action="{{ route('public.gallery.zip', $gallery->code) }}" onsubmit="return copySelected(this)">@csrf<div class="zip-inputs"></div><button class="btn btn-outline-dark">Download selected</button></form><form data-download-form method="POST" action="{{ route('public.gallery.zip-all',$gallery->code) }}">@csrf<button class="btn btn-dark">Download all photos</button></form></div>
    @endif
    <div class="mt-4">{{ $photos->links() }}</div>
</div>
<div class="modal fade" id="lightbox" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content bg-dark"><div class="modal-header"><a data-download-link id="lightbox-download" class="btn btn-light" hidden>Download</a><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body text-center position-relative"><button class="btn btn-light position-absolute start-0 top-50" id="previous" type="button">‹</button><img id="lightbox-image" class="img-fluid" style="max-height:85vh" alt="Gallery preview"><button class="btn btn-light position-absolute end-0 top-50" id="next" type="button">›</button></div></div></div></div>
@endsection
@push('scripts')
<script>
let proofCount=Number(document.getElementById('selection-count')?.textContent||0);let actionBusy=false;
document.querySelectorAll('.action').forEach(b => b.onclick = async () => {
    if(actionBusy||b.disabled)return;actionBusy=true;
    const proof=b.classList.contains('proof-action'),selected=b.classList.contains('btn-danger'),card=b.closest('.proof-card');
    const original=b.innerHTML;
    const controls=[...document.querySelectorAll('.action,#complete-selection')].map(el=>[el,el.disabled]);controls.forEach(([el])=>el.disabled=true);
    const render=value=>{b.classList.toggle('btn-danger',value);b.classList.toggle('btn-outline-danger',!value);b.textContent=value?'\u2713 Selected':'Select photo';b.setAttribute('aria-pressed',String(value));['border','border-danger','border-3'].forEach(cls=>card.classList.toggle(cls,value));};
    if(proof)render(!selected);
    const restore=PhotoHubFeedback.busy(b,proof?(!selected?'Selected':'Select photo'):'Saving...');
    try{
        const r=await fetch(b.dataset.url,{method:'POST',headers:PhotoHubFeedback.headers()});
        if(!r.ok)throw new Error(await PhotoHubFeedback.errorMessage(r,'Could not save this change. Please retry.'));
        const j=await r.json();restore();
        if(proof){render(j.selected);proofCount+=(j.selected?1:0)-(selected?1:0);document.getElementById('selection-count').textContent=proofCount;document.getElementById('selection-help').textContent=proofCount?'Selected photos are marked in red.':'Select at least one photo first.';}
        else{b.innerHTML=original;b.classList.toggle('btn-success',j.favorited);PhotoHubFeedback.toast(j.favorited?'Added to favourites.':'Removed from favourites.');}
    }catch(error){restore();if(proof)render(selected);else b.innerHTML=original;PhotoHubFeedback.toast(error instanceof TypeError?'Could not save this change. Check your connection and try again.':error.message,'error');}
    finally{controls.forEach(([el,disabled])=>el.disabled=disabled);if(proof)document.getElementById('complete-selection').disabled=proofCount===0;actionBusy=false;}
});
function copySelected(form) {
    const box = form.querySelector('.zip-inputs'); box.innerHTML = '';
    const selected=document.querySelectorAll('.photo-choice:checked'); if(!selected.length){PhotoHubFeedback.toast('Select at least one photo.','error');return false} selected.forEach(c => { const i=document.createElement('input'); i.type='hidden'; i.name='photos[]'; i.value=c.value; box.appendChild(i); }); return true;
}
function showImage(){const img=galleryImages[current];lightbox.src=img.src;const link=document.getElementById('lightbox-download');link.hidden=!img.dataset.download;link.href=img.dataset.download||'#'}
const galleryImages=[...document.querySelectorAll('.gallery-image')]; let current=0; const lightbox=document.getElementById('lightbox-image'); galleryImages.forEach((img,index)=>img.addEventListener('click',()=>{current=index;showImage()})); document.getElementById('previous').onclick=()=>{current=(current-1+galleryImages.length)%galleryImages.length;showImage()}; document.getElementById('next').onclick=()=>{current=(current+1)%galleryImages.length;showImage()};
</script>
@endpush
