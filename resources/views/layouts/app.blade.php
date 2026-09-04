<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/photohub-logo-web.png') }}">
    <title>@yield('title','PhotoHub')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('css/photohub.css') }}" rel="stylesheet">
</head>
<body>
@auth
@php
    $isAdmin = auth()->user()->is_super_admin;
    $unreadCount = auth()->user()->unreadNotifications()->count();
    $navigation = [
        'Workspace' => [
            ['dashboard','dashboard','grid-1x2-fill','Overview','dashboard.view'],
            ['calendar','calendar','calendar3','Calendar','bookings.view'],
            ['tasks.index','tasks.*','check2-square','Tasks','bookings.view'],
        ],
        'Clients & work' => [
            ['customers.index','customers.*','people-fill','Customers','customers.view'],
            ['leads.index','leads.*','person-plus-fill','Leads','customers.view'],
            ['bookings.index','bookings.*','calendar-check-fill','Bookings','bookings.view'],
            ['shoots.index','shoots.*','camera-fill','Shoots','bookings.view'],
            ['galleries.index','galleries.*','images','Galleries','galleries.view'],
            ['galleries.links.index','galleries.links.*','link-45deg','Gallery links','galleries.manage'],
            ['portfolio.index','portfolio.*','collection-fill','Portfolio','galleries.manage'],
        ],
        'Business' => [
            ['quotations.index','quotations.*','file-earmark-text-fill','Quotations','finance.view'],
            ['invoices.index','invoices.*','receipt-cutoff','Invoices','finance.view'],
            ['expenses.index','expenses.*','wallet2','Expenses','finance.view'],
            ['orders.index','orders.*','bag-check-fill','Photo orders','finance.view'],
            ['prints.index','prints.*','printer-fill','Print orders','finance.view'],
            ['reports.index','reports.*','bar-chart-fill','Reports','reports.view'],
        ],
        'Team & communication' => [
            ['messages.index','messages.*','chat-dots-fill','Messages','customers.view'],
            ['contracts.index','contracts.*','file-earmark-lock-fill','Contracts','customers.view'],
            ['reviews.index','reviews.*','star-fill','Reviews','customers.view'],
            ['equipment.index','equipment.*','briefcase-fill','Equipment','staff.manage'],
            ['staff.index','staff.*','person-badge-fill','Staff','staff.view'],
        ],
        'Configuration' => [
            ['packages.index','packages.*','box-seam-fill','Packages','settings.manage'],
            ['activity.index','activity.*','clock-history','Activity log','settings.manage'],
            ['settings.index','settings.*','gear-fill','Settings','settings.manage'],
        ],
    ];
@endphp
@if(session('impersonator_id'))
<div class="alert alert-warning rounded-0 border-0 mb-0 d-flex flex-wrap align-items-center justify-content-center gap-3" role="status"><strong><i class="bi bi-eye-fill"></i> View-as-owner mode</strong><span>You are viewing {{ $currentBusiness->name ?? 'this studio' }} as {{ auth()->user()->name }}. Changes are disabled.</span><form method="POST" action="{{ route('admin.impersonation.stop') }}">@csrf<button class="btn btn-sm btn-dark">Return to platform administration</button></form></div>
@endif
<div class="app-shell">
    <aside class="sidebar offcanvas-lg offcanvas-start" id="sidebar" tabindex="-1" aria-label="Main navigation">
        <div class="sidebar-head">
            <a href="{{ $isAdmin?route('admin.index'):route('dashboard') }}" class="brand text-decoration-none"><i class="bi bi-aperture"></i><span>PhotoHub</span></a>
            <button class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" aria-label="Close navigation"></button>
            <div class="studio-pill"><span class="online-dot"></span><span>{{ isset($currentBusiness)?$currentBusiness->name:'Platform control' }}</span></div>
        </div>
        <nav class="nav flex-column">
            @if($isAdmin)
                <div class="nav-flat-list">
                    <a class="nav-link {{ request()->routeIs('admin.index','admin.businesses.*')?'active':'' }}" href="{{ route('admin.index') }}"><i class="bi bi-buildings-fill"></i><span>Businesses</span></a>
                    <a class="nav-link {{ request()->routeIs('admin.plans')?'active':'' }}" href="{{ route('admin.plans') }}"><i class="bi bi-credit-card-2-front-fill"></i><span>Plans</span></a>
                    <a class="nav-link {{ request()->routeIs('admin.settings')?'active':'' }}" href="{{ route('admin.settings') }}"><i class="bi bi-sliders2"></i><span>Platform settings</span></a>
                    <a class="nav-link {{ request()->routeIs('admin.support')?'active':'' }}" href="{{ route('admin.support') }}"><i class="bi bi-life-preserver"></i><span>Support requests</span></a>
                </div>
            @else
                @foreach($navigation as $section => $links)
                    @php($visibleLinks=collect($links)->filter(fn($link)=>Route::has($link[0]) && auth()->user()->hasPermission($link[4])))
                    @if($visibleLinks->isNotEmpty())
                        <div class="nav-flat-list">
                            @foreach($visibleLinks as [$route,$pattern,$icon,$label,$permission])
                                <a class="nav-link {{ request()->routeIs($pattern)?'active':'' }}" href="{{ route($route) }}"><i class="bi bi-{{ $icon }}"></i><span>{{ $label }}</span></a>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            @endif
        </nav>
        <div class="sidebar-foot">
            <div class="sidebar-avatar">{{ str(auth()->user()->name)->substr(0,1)->upper() }}</div>
            <div class="sidebar-user"><strong>{{ auth()->user()->name }}</strong><small>{{ $isAdmin?'Super administrator':(auth()->user()->memberships()->first()?->job_title ?? 'Team member') }}</small></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="sidebar-logout" title="Sign out" aria-label="Sign out"><i class="bi bi-box-arrow-right"></i></button></form>
        </div>
    </aside>
    <main class="main-content">
        <header class="topbar">
            <button class="mobile-menu d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Open navigation"><i class="bi bi-list"></i></button>
            @unless($isAdmin)<form action="{{ route('search') }}" class="top-search d-none d-md-flex"><i class="bi bi-search"></i><input name="q" placeholder="Search PhotoHub…" aria-label="Search"></form>@endunless
            <div class="ms-auto d-flex align-items-center gap-3">
                <a href="{{ route('notifications.index') }}" class="notification-bell {{ $unreadCount?'has-unread':'' }}" title="{{ $unreadCount }} unread notifications" aria-label="{{ $unreadCount }} unread notifications"><i class="bi bi-bell-fill"></i>@if($unreadCount)<span class="notification-count">{{ $unreadCount>99?'99+':$unreadCount }}</span>@endif</a>
                <div class="topbar-user d-none d-sm-block"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></div>
            </div>
        </header>
        <section class="page-body">
            @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
            @yield('content')
        </section>
    </main>
</div>
@else
    @yield('content')
@endauth
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
