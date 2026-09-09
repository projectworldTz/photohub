# Reports and trends

Open **Reports** from the studio sidebar. Choose a quick range (7, 30 or 90 days, this month, or year to date), or enter From/To dates. Changing dates, grouping or comparison refreshes every chart, metric and breakdown together. Apply filters also works as a normal form submission without JavaScript.

Available charts:

- **Revenue trend:** collected revenue, a trailing three-interval moving average, and optional previous-period revenue.
- **Income, expenses & net cash flow:** revenue/expense columns with a net cash-flow line.
- **Cumulative cash flow:** an area chart of running revenue minus expenses within the selected range.
- **Cash-flow fluctuations:** positive/negative columns showing how each interval's net cash flow changed from the preceding interval.

Hover, tap or keyboard-focus a chart for exact amounts; arrow keys move between intervals. Expand **View chart data & exact fluctuations** for an accessible table. Everything is rendered from local assets, with no external chart service or CDN.

Daily, weekly and monthly grouping are available. Automatic grouping uses days for up to 45 days, weeks for up to 240 days, and months for longer ranges. Weekly intervals start at the selected From date. First and last monthly intervals can be partial. Daily charts are limited to 366 days; other ranges can span up to ten years.

The previous period has the same number of days and immediately precedes the selected range. Its chart intervals align by elapsed days; tooltips show the exact comparison dates. Missing days contribute zero. The moving average uses up to the most recent three intervals and is not a forecast. Fluctuation is an absolute money change, not a percentage; the first interval has no predecessor in the selected range.

Only completed payments contribute revenue. Reversed payments are excluded. Net cash flow is recorded receipts minus expenses, not accrual profit or a bank-account balance. Current outstanding invoices are explicitly labelled as an all-date snapshot. A zero previous baseline displays “No previous baseline” instead of an undefined percentage.

CSV and PDF continue to export financial totals using the selected inclusive date range. The interactive charts and detailed chart table are on the report page. Tenant scoping and the existing `reports.view` permission apply to HTML, live JSON updates and exports.

Implementation: `ReportRequest` validates common filters; `ReportService::trends()` aggregates daily values and calculates intervals/comparisons; the existing report endpoint returns a Blade partial for live updates. SVG charts are in `public/js/reports.js`, with styling in `public/css/reports.css`. No migrations or new dependencies are required.

Verification: `php artisan test --compact` passed 74 tests (406 assertions). Report-specific coverage includes inclusive dates, zero days, negative values, comparisons, moving averages, fluctuations, tenant isolation, permissions, filter validation and exports. Headless Chrome checks also verified chart rendering, keyboard interaction, asynchronous filtering, export-link updates, mobile overflow and retention of the last successful report on invalid input.
