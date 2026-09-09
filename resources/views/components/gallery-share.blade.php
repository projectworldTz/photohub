@props(['gallery', 'url', 'mode' => 'selection'])
@php
    $id = 'invitation-'.$mode;
    $message = 'Hello'.($gallery->customer?->first_name ? ' '.$gallery->customer->first_name : '').",\n\n";
    $message .= $gallery->business->name.' invites you to '. $gallery->name.".\n";
    if ($gallery->event) $message .= $gallery->event."\n";
    if ($gallery->event_date) $message .= $gallery->event_date->format('d F Y')."\n";
    $message .= $mode === 'delivery' ? "\nYour finished photographs are ready. View and download your collection here:\n" : "\nYour photo gallery is ready. Choose ".($gallery->selection_limit ? 'up to '.$gallery->selection_limit : 'your favourite').' photos for editing here:'."\n";
    $message .= $url;
    if ($gallery->expires_at) $message .= "\n\nAvailable until ".$gallery->expires_at->format('d F Y').'.';
    $message .= "\n\nThank you for choosing ".$gallery->business->name.'.';
    $phone = preg_replace('/\D/', '', $gallery->customer?->whatsapp ?: $gallery->customer?->phone ?: '');
@endphp
<div class="rounded-3 p-3 mt-3" style="background:#f5f2eb;border:1px solid #e6dfd0">
    <small class="text-uppercase text-muted">{{ $gallery->business->name }}</small>
    <h5 class="mt-2" style="font-family:Georgia,serif">{{ $gallery->name }}</h5>
    <label class="small text-muted mb-2" for="{{ $id }}">Client invitation — ready to share</label>
    <textarea id="{{ $id }}" class="form-control" rows="8" readonly>{{ $message }}</textarea>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="button" class="btn btn-dark" data-copy-invitation="{{ $id }}">Copy invitation</button>
        <a target="_blank" rel="noopener noreferrer" class="btn btn-success" href="https://wa.me/{{ $phone }}?text={{ urlencode($message) }}">WhatsApp invitation</a>
        <a target="_blank" rel="noopener noreferrer" class="btn btn-outline-dark" href="{{ $url }}">Preview client page</a>
    </div>
    <small class="d-block mt-2" role="status" id="{{ $id }}-status"></small>
</div>
@once
<script>
document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-copy-invitation]');if(!button)return;
    const field=document.getElementById(button.dataset.copyInvitation),status=document.getElementById(field.id+'-status');
    try{await navigator.clipboard.writeText(field.value);status.textContent='Invitation copied. Ready to send to your client.'}
    catch(error){field.focus();field.select();status.textContent='Select and copy the invitation above to share it.'}
});
</script>
@endonce
