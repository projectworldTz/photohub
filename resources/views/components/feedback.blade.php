@once
<link rel="stylesheet" href="{{ asset('css/feedback.css') }}">
<script src="{{ asset('js/feedback.js') }}" defer></script>
@if(session('selection_success'))
    <span hidden data-feedback-flash="success" data-feedback-message="Your photo selections were submitted successfully."></span>
@elseif(session('success'))
    <span hidden data-feedback-flash="success" data-feedback-message="{{ session('success') }}"></span>
@endif
@if($errors->any())
    <span hidden data-feedback-flash="error" data-feedback-message="{{ $errors->first() }}"></span>
@endif
@endonce
