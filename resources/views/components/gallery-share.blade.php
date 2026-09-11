@props(['gallery', 'url', 'mode' => 'selection', 'compact' => false])
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
<div @class(['rounded-3 p-3 mt-3' => !$compact]) @if(!$compact) style="background:#f5f2eb;border:1px solid #e6dfd0" @endif>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary" data-copy-invitation="{{ $id }}">Copy invitation</button>
        <a target="_blank" rel="noopener noreferrer" class="btn btn-outline-success" href="https://wa.me/{{ $phone }}?text={{ urlencode($message) }}">Send via WhatsApp</a>
    </div>
    @unless($compact)<small class="d-block mt-2 text-muted">Copy the ready-to-send message or share it through WhatsApp.</small>@endunless
    <small class="d-block mt-2" role="status" id="{{ $id }}-status"></small>
    <details @class(['mt-2 small' => $compact, 'mt-3' => !$compact]) id="{{ $id }}-details">
        <summary>Preview invitation</summary>
        <label class="small text-muted my-2" for="{{ $id }}">Message for your client</label>
        <textarea id="{{ $id }}" class="form-control" rows="8" readonly>{{ $message }}</textarea>
        <a target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-dark mt-3" href="{{ $url }}">View client gallery</a>
    </details>
</div>
