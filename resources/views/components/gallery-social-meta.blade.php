@props(['gallery', 'mode' => 'selection'])
@php
    $title = $gallery->name.' | '.$gallery->business->name;
    $description = $mode === 'delivery' ? 'Your finished photographs are ready. Open your gallery to view and download your collection.' : 'You are invited to your photo gallery. Explore your moments and choose your favourite photographs.';
@endphp
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="same-origin">
<meta name="description" content="{{ $description }}">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $title }}">
<meta property="og:site_name" content="{{ $gallery->business->name }}">
<meta property="og:description" content="{{ $description }}">
@if($gallery->business->logo_path)
    <meta property="og:image" content="{{ route('public.business.logo', $gallery->business->slug) }}">
    <meta property="og:image:alt" content="{{ $gallery->business->name }} logo">
@endif
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
<link href="{{ asset('css/gallery-invitation.css') }}" rel="stylesheet">
