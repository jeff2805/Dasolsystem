<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VisitorOrigin;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Resort;
use App\Services\ReportService;
use App\Support\Xlsx;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tourism statistics for the Municipal Tourism Office.
 *
 * The MTO exports visitor and room-use statistics only.
 * Resort income and revenue are deliberately excluded.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports
    ) {}

    /**
     * Display tourism statistics.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Booking::class);

        [$from, $to, $resort, $basis] = $this->filters($request);

        $trend = $this->reports->monthlyTrend(
            $from,
            $to,
            $resort?->id,
            $basis
        );

        $mix = $this->reports->statusMix(
            $from,
            $to,
            $resort?->id,
            $basis
        );

        $allResorts = Resort::query()
            ->orderBy('name')
            ->get(['id', 'name', 'barangay']);

        return view('admin.reports.index', [
            'from' => $from,
            'to' => $to,
            'resort' => $resort,
            'basis' => $basis,

            'resorts' => $allResorts->pluck('name', 'id'),

            'summary' => $this->reports->summary(
                $from,
                $to,
                $resort?->id,
                $basis
            ),

            'totals' => $this->reports->systemTotals(),

            'trend' => $trend,
            'mix' => $mix,

            'reservations' => $this->reports->reservations(
                $from,
                $to,
                $resort?->id,
                $basis
            ),

            'guests' => $this->reports->guests(
                $from,
                $to,
                $resort?->id,
                $basis
            ),

            'topResorts' => $this->reports->topResorts(
                $from,
                $to,
                8,
                $basis
            ),

            'origins' => $this->reports->visitorOrigins(
                $from,
                $to,
                $resort?->id
            ),

            'registrations' => $this->reports->registrations(
                $from,
                $to
            ),

            'accommodation' => $this->reports->accommodationRegister(
                $from,
                $to,
                $resort?->id
            ),
        ]);
    }

    /**
     * Export the visitor register to Excel.
     *
     * Only the requested 12 columns are included.
     */
    public function accommodation(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Booking::class);

        [$from, $to, $resort] = $this->filters($request);

        /*
         * The MTO Excel return is grouped by resort: one row per resort,
         * regardless of how many bookings it receives during the period.
         * The numeric columns are additive down the sheet, so the final
         * GRAND TOTAL row reconciles with the individual resort rows.
         */
        $originColumns = [
            'Pangasinan' => 'pangasinan',
            'Ilocos Region' => 'ilocos',
            'NCR' => 'ncr',
            'Other PH' => 'other_ph',
            'Foreign' => 'foreign',
        ];

        $numericColumns = [
            'Check-ins',
            'Guest Nights',
            'Day Tour',
            'Room Use',
            'Male',
            'Female',
            ...array_keys($originColumns),
        ];

        $headings = ['Resort', ...$numericColumns];

        $fills = [
            'D9C9C4',
            'BFD9D8',
            '7ED0A5',
            'A9C4F5',
            'A9C4F5',
            'F6A96B',
            'F15FA6',
            'FFE07A',
            'FFE07A',
            'FFE07A',
            'FFE07A',
            'FFE07A',
            'FFE07A',
        ];

        $widths = [
            28, 12, 14, 12, 13, 10, 10,
            15, 14, 10, 12, 14,
        ];

        $bookings = $this->reports
            ->accommodationRegister($from, $to, $resort?->id);

        $rows = $bookings
            ->groupBy('resort')
            ->map(function ($resortBookings, string $resortName) use ($originColumns): array {
                $originTotals = [];

                foreach ($originColumns as $heading => $originKey) {
                    $originTotals[$heading] = $resortBookings->sum(
                        fn (array $booking): int => (int) (
                            $booking['origin'][$originKey] ?? 0
                        )
                    );
                }

                return [
                    $resortName,
                    $resortBookings->count(),
                    $resortBookings->sum('guest_nights'),
                    $resortBookings->sum('day_tour'),
                    $resortBookings->sum('rooms_used'),
                    $resortBookings->sum('male'),
                    $resortBookings->sum('female'),
                    ...array_values($originTotals),
                ];
            })
            ->sortBy(fn (array $row): string => (string) $row[0], SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        // Add a numeric grand total for every measure, not a sum of labels.
        $grandTotal = ['GRAND TOTAL'];

        foreach (range(1, count($numericColumns)) as $columnIndex) {
            $grandTotal[] = (int) $rows->sum(
                fn (array $row): int => (int) ($row[$columnIndex] ?? 0)
            );
        }

        $rows->push($grandTotal);

        $file = (new Xlsx(
            'Tourism Statistics',
            $headings,
            $fills,
            $widths
        ))->write($rows, true);

        $name = 'dasol-tourism-statistics-'
            .$from->format('Y-m-d')
            .'-to-'
            .$to->format('Y-m-d')
            .'.xlsx';

        return response()->download($file, $name, [
            'Content-Type' =>
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Export tourism statistics to CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Booking::class);

        [$from, $to, $resort] = $this->filters($request);

        $name = 'dasol-tourism-statistics-'
            .$from->format('Ymd')
            .'-to-'
            .$to->format('Ymd')
            .'.csv';

        return response()->streamDownload(
            function () use ($from, $to, $resort): void {
                $handle = fopen('php://output', 'wb');

                if ($handle === false) {
                    throw new \RuntimeException(
                        'Unable to open the CSV output stream.'
                    );
                }

                // Help Microsoft Excel recognize UTF-8.
                fwrite($handle, "\xEF\xBB\xBF");

                fputcsv($handle, [
                    'Resort',
                    'GCheckin',
                    'O-night',
                    'R-used',
                    'Male',
                    'Female',
                    'Pangasinan',
                    'Elyu',
                    'BC',
                    'Ilocos',
                    'NCR',
                    'Bul',
                ]);

                foreach (
                    $this->reports->accommodationRegister(
                        $from,
                        $to,
                        $resort?->id
                    ) as $row
                ) {
                    $origin = $row['origin'] ?? [];

                    fputcsv($handle, [
                        $row['resort'] ?? '',
                        $row['check_in']?->toDateString() ?? '',
                        $row['guest_nights'] ?? 0,
                        $row['rooms_used'] ?? 0,
                        $row['male'] ?? 0,
                        $row['female'] ?? 0,

                        $this->originCount($origin, [
                            'pangasinan',
                        ]),

                        $this->originCount($origin, [
                            'elyu',
                            'la union',
                        ]),

                        $this->originCount($origin, [
                            'bc',
                        ]),

                        $this->originCount($origin, [
                            'ilocos',
                        ]),

                        $this->originCount($origin, [
                            'ncr',
                            'national capital region',
                        ]),

                        $this->originCount($origin, [
                            'bul',
                            'bulacan',
                        ]),
                    ]);
                }

                fclose($handle);
            },
            $name,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]
        );
    }

    /**
     * Resolve an origin count using existing enum values or short labels.
     *
     * @param array<string, int|float> $origin
     * @param list<string> $aliases
     */
    private function originCount(
        array $origin,
        array $aliases
    ): int {
        $normalize = static function (string $value): string {
            return strtolower(
                preg_replace('/[^a-z0-9]/i', '', $value) ?? ''
            );
        };

        $wanted = array_map($normalize, $aliases);

        foreach (VisitorOrigin::cases() as $case) {
            $value = $normalize($case->value);
            $label = $normalize($case->shortLabel());

            if (
                in_array($value, $wanted, true)
                || in_array($label, $wanted, true)
            ) {
                return (int) ($origin[$case->value] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Read and validate the report filters.
     *
     * @return array{
     *     0: CarbonImmutable,
     *     1: CarbonImmutable,
     *     2: ?Resort,
     *     3: string
     * }
     */
    private function filters(Request $request): array
    {
        $parse = function (mixed $value): ?CarbonImmutable {
            if (
                ! is_string($value)
                || ! preg_match(
                    '/^\d{4}-\d{2}-\d{2}$/',
                    $value
                )
            ) {
                return null;
            }

            try {
                $date = CarbonImmutable::createFromFormat(
                    '!Y-m-d',
                    $value
                );

                // Reject invalid dates such as 2026-02-31.
                if (
                    $date === false
                    || $date->format('Y-m-d') !== $value
                ) {
                    return null;
                }

                return $date;
            } catch (\Throwable) {
                return null;
            }
        };

        // Default: the current month and the eleven preceding months.
        $to = $parse($request->query('to'))
            ?? CarbonImmutable::today();

        $from = $parse($request->query('from'))
            ?? $to->startOfMonth()->subMonths(11);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        // Limit the maximum report period.
        if ($from->diffInMonths($to) > 36) {
            $from = $to->subMonths(36);
        }

        $resortId = $request->query('resort');

        $resort = filled($resortId)
            ? Resort::query()->whereKey($resortId)->first()
            : null;

        $basis = $request->query('basis') === 'requested'
            ? 'requested'
            : 'stay';

        return [$from, $to, $resort, $basis];
    }
}
