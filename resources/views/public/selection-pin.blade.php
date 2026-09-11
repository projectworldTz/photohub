<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $gallery->name }} | {{ $gallery->business->name }}</title>
<link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
<link href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
<x-gallery-social-meta :gallery="$gallery" />
</head><body>
<x-feedback />
<x-gallery-invitation :gallery="$gallery" mode="locked" />
<main id="unlock-gallery" class="invitation-unlock">
<form method="POST" action="{{ route('selection.unlock',$token) }}" class="card border-0 rounded-4 p-4 p-md-5 mx-auto" style="max-width:440px">
@csrf<h2 class="h3">Your private collection</h2>
<p class="text-muted">Enter the Gallery PIN supplied by {{ $gallery->business->name }}.</p>
<label for="gallery-pin" class="form-label">Gallery PIN</label>
<input id="gallery-pin" name="pin" type="password" inputmode="numeric" autocomplete="one-time-code" class="form-control form-control-lg @error('pin') is-invalid @enderror" required>
@error('pin')<div class="invalid-feedback">{{ $message }}</div>@enderror
<button class="btn btn-dark btn-lg mt-3">Enter gallery</button>
</form></main></body></html>
