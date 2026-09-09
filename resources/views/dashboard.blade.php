@extends('layouts.app')
@section('title','Dashboard — PhotoHub')
@section('content')
<div class="overview-page">
<x-page-header title="Studio overview" subtitle="Book a shoot, upload finished photos and send a download link."><a href="{{ route('bookings.create') }}" class="btn btn-primary">New booking</a></x-page-header>
<x-storage-usage :business="$currentBusiness" compact />
<div class="row g-2 mb-3 overview-workflow">
    @foreach([
        ['1. Book the shoot', 'Add the client, date and shoot details.', 'bookings.create', 'bookings.manage'],
        ['2. Upload photos', 'Create a gallery and add the shoot photos.', 'galleries.create', 'galleries.manage'],
        ['3. Collect selections', 'Optional: let your client choose photos first.', 'galleries.links.index', 'galleries.manage'],
        ['4. Deliver final photos', 'Open the gallery to upload and share the finished photos.', 'galleries.index', 'galleries.view'],
    ] as [$step, $description, $destination, $permission])
        @if(auth()->user()->hasPermission($permission))
            <div class="col-sm-6 col-xl-3"><a class="content-card d-block h-100 text-decoration-none text-body" href="{{ route($destination) }}"><h5>{{ $step }}</h5><p class="text-muted mb-0">{{ $description }}</p></a></div>
        @endif
    @endforeach
</div>
<div class="row g-3 mb-4">@foreach([['Today’s shoots',$todayShoots,'camera'],['Upcoming bookings',$upcomingBookings,'calendar-event'],['Pending editing',$pendingEditing,'brush'],['Awaiting delivery',$awaitingDelivery,'send'],['Unpaid invoices',$unpaidInvoices,'receipt'],['Customers',$customersCount,'people'],['Galleries',$galleriesCount,'images'],['Photos / storage',$photosCount.' / '.$storageMb.' MB','database']] as [$label,$value,$icon])<div class="col-sm-6 col-xl-3"><div class="metric-card"><div class="metric-icon blue"><i class="bi bi-{{ $icon }}"></i></div><div><small>{{ $label }}</small><h3>{{ $value }}</h3></div></div></div>@endforeach</div>
<div class="row g-3 mb-4">@foreach([['Monthly revenue',$monthlyRevenue],['Monthly expenses',$monthlyExpenses],['Monthly profit',$monthlyRevenue-$monthlyExpenses],['Outstanding',$outstanding]] as [$label,$value])<div class="col-sm-6 col-xl-3"><div class="metric-card"><div><small>{{ $label }}</small><h3>{{ $currentBusiness->currency }} {{ number_format((float)$value) }}</h3></div></div></div>@endforeach</div>
<div class="row g-4 mb-4"><div class="col-xl-8"><div class="content-card"><div class="d-flex justify-content-between"><h5>Six-month financial trend</h5><small>Revenue / expenses</small></div><div class="d-flex align-items-end gap-3 mt-4" style="height:220px">@foreach($analytics['monthly'] as $month)<div class="flex-fill text-center h-100 d-flex flex-column justify-content-end"><div class="d-flex align-items-end justify-content-center gap-1"><div class="bg-primary rounded-top" title="Revenue {{ number_format($month['revenue']) }}" style="width:18px;height:{{ max(2,$month['revenue']/$analytics['maxMoney']*170) }}px"></div><div class="bg-warning rounded-top" title="Expenses {{ number_format($month['expenses']) }}" style="width:18px;height:{{ max(2,$month['expenses']/$analytics['maxMoney']*170) }}px"></div></div><small class="mt-2">{{ $month['label'] }}</small></div>@endforeach</div></div></div>
<div class="col-xl-4"><div class="content-card h-100"><h5>Delivery deadlines</h5>@foreach([['Overdue','overdue','danger'],['Due within 3 days','dueSoon','warning'],['On time','onTime','success']] as [$label,$key,$color])<div class="d-flex justify-content-between border-bottom py-3"><span>{{ $label }}</span><span class="badge text-bg-{{ $color }}">{{ $analytics['deliveries'][$key] }}</span></div>@endforeach<h5 class="mt-4">Booking status</h5>@forelse($analytics['bookingStatuses'] as $status=>$count)<span class="badge text-bg-light me-1 mb-1">{{ str($status)->replace('_',' ')->title() }} {{ $count }}</span>@empty<p class="text-muted">No bookings yet.</p>@endforelse</div></div></div>
<div class="row g-4"><div class="col-lg-8"><div class="content-card"><h5>Package performance</h5><table class="table"><thead><tr><th>Package</th><th>Category</th><th>Bookings</th></tr></thead><tbody>@forelse($analytics['packages'] as $package)<tr><td>{{ $package->name }}</td><td>{{ $package->category }}</td><td>{{ $package->bookings_count }}</td></tr>@empty<tr><td colspan="3">No package bookings yet.</td></tr>@endforelse</tbody></table><a href="{{ route('reports.index') }}" class="btn btn-light">Open reports</a></div></div><div class="col-lg-4"><div class="content-card"><h5>Recent activity</h5>@forelse($activity as $item)<div class="activity-item"><span class="activity-dot"></span><div><strong>{{ str($item->action)->replace(['.','_'],' ')->title() }}</strong><small>{{ $item->created_at->diffForHumans() }}</small></div></div>@empty<div class="empty-mini">No activity yet.</div>@endforelse</div></div></div>
</div>
@endsection
