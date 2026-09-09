<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ReportTrendsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->business = Business::where('slug', 'lenscraft-studio')->firstOrFail();
        $this->owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->actingAs($this->owner)->withSession(['business_id' => $this->business->id]);
    }

    private function payment(string $date, float $amount, string $status = 'completed', ?Business $business = null): void
    {
        Payment::create(['business_id' => ($business ?? $this->business)->id, 'customer_id' => $business ? null : Customer::forBusiness($this->business->id)->first()->id, 'amount' => $amount, 'payment_date' => $date, 'method' => 'cash', 'status' => $status, 'received_by' => $this->owner->id]);
    }

    private function activity(): void
    {
        $this->payment('2030-12-30 10:00:00', 50);
        $this->payment('2031-01-01 09:00:00', 100);
        $this->payment('2031-01-03 23:59:59', 200);
        $this->payment('2031-01-02 12:00:00', 900, 'reversed');
        $this->payment('2031-01-04 00:00:00', 1000);
        foreach (['2031-01-01' => 20, '2031-01-03' => 80] as $date => $amount) {
            Expense::create(['business_id' => $this->business->id, 'date' => $date, 'amount' => $amount, 'category' => 'Travel', 'description' => 'Test travel', 'recorded_by' => $this->owner->id]);
        }
    }

    public function test_trends_fill_empty_dates_and_calculate_comparison_and_fluctuation(): void
    {
        $this->activity();
        $from = Carbon::parse('2031-01-01')->startOfDay();
        $to = Carbon::parse('2031-01-03')->endOfDay();
        $service = app(ReportService::class);
        $data = $service->trends($this->business->id, $from, $to, 'day');
        $this->assertCount(3, $data['series']);
        $this->assertEquals([100, 0, 200], array_column($data['series'], 'revenue'));
        $this->assertEquals([80, 80, 200], array_column($data['series'], 'cumulative'));
        $this->assertEquals([null, -80, 120], array_column($data['series'], 'fluctuation'));
        $this->assertEquals([100, 50, 100], array_column($data['series'], 'moving_average'));
        $this->assertEquals(50, $data['previous']['revenue']);
        $this->assertEquals(500, $data['changes']['revenue']['percent']);
        $this->assertEquals(120, $data['insights']['swing']['fluctuation']);
        $financial = $service->financial($this->business->id, $from, $to);
        foreach (['revenue', 'expenses', 'profit', 'bookings'] as $key) {
            $this->assertEquals($financial[$key], $data['totals'][$key]);
        }
    }

    public function test_ajax_filters_refresh_charts_and_breakdowns_without_foreign_data(): void
    {
        $this->activity();
        $foreign = Business::create(['name' => 'Other studio', 'slug' => 'reports-other', 'email' => 'reports-other@example.test', 'status' => 'active']);
        $this->payment('2031-01-03 10:00:00', 99999, 'completed', $foreign);
        $response = $this->getJson('/reports?from=2031-01-01&to=2031-01-03&interval=week&compare=1')->assertOk();
        $response->assertJsonCount(1, 'charts.series')->assertJsonPath('charts.totals.revenue', 300)->assertJsonPath('charts.totals.expenses', 100);
        $this->assertStringContainsString('chart-revenue', $response->json('html'));
        $this->assertStringContainsString('Payment methods', $response->json('html'));
        $this->getJson('/reports?from=2031-01-03&to=2031-01-03&interval=day')->assertOk()->assertJsonPath('charts.totals.revenue', 200)->assertJsonPath('charts.totals.expenses', 80);
        $this->get('/reports?from=2031-01-01&to=2031-01-03')->assertOk()->assertSee('reports.js')->assertSee('Cash-flow fluctuations');
        if (getenv('REPORT_BROWSER_FIXTURE')) {
            URL::forceRootUrl('http://127.0.0.1:8931');
            $directory = storage_path('app/private/report-browser');
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            file_put_contents($directory.'/page.html', $this->get('/reports?from=2031-01-01&to=2031-01-03&interval=day')->getContent());
            file_put_contents($directory.'/range.json', $this->getJson('/reports?from=2031-01-01&to=2031-01-03&interval=day')->getContent());
            file_put_contents($directory.'/single.json', $this->getJson('/reports?from=2031-01-03&to=2031-01-03&interval=day')->getContent());
        }

    }

    public function test_invalid_filters_are_rejected_on_page_and_exports(): void
    {
        foreach (['/reports', '/reports/csv', '/reports/pdf'] as $route) {
            $this->getJson($route.'?from=not-a-date&to=2031-01-03')->assertUnprocessable();
            $this->getJson($route.'?from=2031-01-04&to=2031-01-03')->assertUnprocessable();
        }
        $this->getJson('/reports?from=2030-01-01&to=2032-01-01&interval=day')->assertUnprocessable();
        $this->getJson('/reports?interval=invalid')->assertUnprocessable();
    }

    public function test_csv_and_pdf_use_the_same_inclusive_dates(): void
    {
        $this->activity();
        $csv = $this->get('/reports/csv?from=2031-01-03&to=2031-01-03')->assertOk()->assertDownload('photohub-report.csv');
        $this->assertStringContainsString('Revenue,200', $csv->streamedContent());
        $this->assertStringContainsString('Expenses,80', $csv->streamedContent());
        $this->get('/reports/pdf?from=2031-01-03&to=2031-01-03')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_empty_and_negative_cashflow_do_not_produce_invalid_percentages(): void
    {
        $service = app(ReportService::class);
        $empty = $service->trends($this->business->id, Carbon::parse('2040-01-01'), Carbon::parse('2040-01-01'), 'day');
        $this->assertNull($empty['changes']['profit']['percent']);
        $this->assertNull($empty['insights']['swing']);
        Expense::create(['business_id' => $this->business->id, 'date' => '2040-01-01', 'amount' => 90, 'category' => 'Other', 'description' => 'No income', 'recorded_by' => $this->owner->id]);
        $negative = $service->trends($this->business->id, Carbon::parse('2040-01-01'), Carbon::parse('2040-01-02'), 'day');
        $this->assertEquals(-90, $negative['totals']['profit']);
        $this->assertEquals([-90, -90], array_column($negative['series'], 'cumulative'));
        $this->assertEquals(90, $negative['series'][1]['fluctuation']);
    }

    public function test_customer_cannot_read_report_json(): void
    {
        $this->actingAs(User::where('email', 'customer@example.com')->firstOrFail())->getJson('/reports')->assertForbidden();
    }
}
