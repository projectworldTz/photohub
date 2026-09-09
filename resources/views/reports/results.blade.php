<div class="report-period mb-3"><strong>{{ $from->format('d M Y') }} — {{ $to->format('d M Y') }}</strong><span class="badge text-bg-light">{{ ucfirst($analytics['interval']) }} intervals</span>
@if($compare)<span class="small text-muted">Compared with {{ $analytics['previous_from'] }} — {{ $analytics['previous_to'] }}</span>@endif</div>
<div class="row g-3 mb-4">
@foreach(['revenue'=>'Collected revenue','expenses'=>'Expenses','profit'=>'Net cash flow','bookings'=>'Bookings'] as $key=>$label)
    @php($change=$analytics['changes'][$key])
    @php($good=$key==='expenses' ? $change['amount']<=0 : $change['amount']>=0)
    <div class="col-6 col-xl-3"><div class="report-metric"><small>{{ $label }}</small><h3>{{ $key==='bookings' ? number_format($report[$key]) : $currentBusiness->currency.' '.number_format($report[$key],2) }}</h3>
    @if($compare)<span class="report-change {{ $change['amount']==0?'neutral':($good?'positive':'negative') }}">{{ $change['amount']>0?'↑':($change['amount']<0?'↓':'→') }} {{ $change['percent']===null ? ($change['amount']==0?'No change':'No previous baseline') : number_format(abs($change['percent']),1).'% '.($change['amount']>=0?'increase':'decrease') }}</span><small class="d-block mt-1">Previous: {{ $key==='bookings' ? number_format($analytics['previous'][$key]) : $currentBusiness->currency.' '.number_format($analytics['previous'][$key],2) }}</small>@endif
    </div></div>
@endforeach
</div>
<div class="report-insights mb-4">
    <div><small>Best revenue interval</small><strong>{{ $analytics['insights']['peak']['label'] ?? 'No revenue yet' }}</strong>@if($analytics['insights']['peak'])<span>{{ $currentBusiness->currency }} {{ number_format($analytics['insights']['peak']['revenue'],2) }}</span>@endif</div>
    <div><small>Largest cash-flow swing</small><strong>{{ $analytics['insights']['swing'] ? ($analytics['insights']['swing']['fluctuation']>0?'Increase':'Decrease').' of '.$currentBusiness->currency.' '.number_format(abs($analytics['insights']['swing']['fluctuation']),2) : 'No fluctuation' }}</strong><span>{{ $analytics['insights']['swing']['label'] ?? 'Intervals with no movement stay at zero.' }}</span></div>
    <div><small>Average daily revenue</small><strong>{{ $currentBusiness->currency }} {{ number_format($analytics['insights']['average_daily_revenue'],2) }}</strong><span>Includes days with no payments.</span></div>
    <div><small>Current outstanding invoices</small><strong>{{ $currentBusiness->currency }} {{ number_format($report['outstanding'],2) }}</strong><span>All dates; current unpaid balance.</span></div>
</div>
<div class="row g-3">
    @foreach([
        'revenue'=>['Revenue trend','Collected payments, previous-period revenue and a trailing 3-interval moving average.'],
        'cashflow'=>['Income, expenses & net cash flow','Compare income and expense columns against the net cash-flow line.'],
        'cumulative'=>['Cumulative cash flow','Running collected revenue minus expenses, starting at zero for this date range.'],
        'fluctuation'=>['Cash-flow fluctuations','Change in net cash flow from one interval to the next. Above zero means an increase.'],
    ] as $id=>$copy)
    <div class="col-xl-6"><section class="content-card report-chart-card" aria-labelledby="chart-title-{{ $id }}"><h5 id="chart-title-{{ $id }}">{{ $copy[0] }}</h5><p class="small text-muted">{{ $copy[1] }}</p><div class="report-chart" id="chart-{{ $id }}" data-chart="{{ $id }}" data-currency="{{ $currentBusiness->currency }}" data-compare="{{ $compare?'1':'0' }}"></div></section></div>
    @endforeach
</div>
<p class="report-note mt-3">Revenue includes completed payments only; reversed payments are excluded. Net cash flow is collected revenue minus recorded expenses, not accrual profit or a bank balance. Comparison intervals align by elapsed days. First/last intervals may be partial; the moving average is descriptive, not a forecast.</p>
<details class="content-card report-data mt-3"><summary>View chart data & exact fluctuations</summary><div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Period</th><th>Revenue</th><th>Expenses</th><th>Net cash flow</th><th>Previous revenue</th><th>Running net</th><th>Change from prior interval</th><th>Bookings</th></tr></thead><tbody>
@foreach($analytics['series'] as $point)<tr><td class="text-nowrap">{{ $point['from'] }} — {{ $point['to'] }}</td>@foreach(['revenue','expenses','profit','previous_revenue','cumulative','fluctuation'] as $key)<td>{{ $point[$key]===null?'—':number_format($point[$key],2) }}</td>@endforeach<td>{{ $point['bookings'] }}</td></tr>@endforeach
</tbody></table></div><small>All amounts in {{ $currentBusiness->currency }}. The first interval has no preceding interval in the selected range.</small></details>
@include('reports.breakdowns')
