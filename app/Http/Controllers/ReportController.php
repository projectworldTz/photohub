<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(ReportRequest $r, ReportService $s): View|JsonResponse
    {
        [$from, $to] = $r->period();
        $businessId = app('currentBusiness')->id;
        $data = ['report' => $s->financial($businessId, $from, $to), 'breakdowns' => $s->breakdowns($businessId, $from, $to), 'analytics' => $s->trends($businessId, $from, $to, $r->validated('interval')), 'compare' => $r->boolean('compare'), 'from' => $from, 'to' => $to];
        if ($r->expectsJson()) {
            return response()->json(['html' => view('reports.results', $data)->render(), 'charts' => $data['analytics']])->header('Cache-Control', 'private, no-store');
        }

        return view('reports.index', $data);
    }

    public function csv(ReportRequest $r, ReportService $s): StreamedResponse
    {
        [$from, $to] = $r->period();
        $data = $s->financial(app('currentBusiness')->id, $from, $to);

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Metric', 'Amount']);
            foreach ($data as $k => $v) {
                fputcsv($out, [str($k)->title(), $v]);
            }fclose($out);
        }, 'photohub-report.csv', ['Content-Type' => 'text/csv']);
    }
}
