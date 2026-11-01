<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Resort;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Reservations across every resort, for the Municipal Tourism Office.
 *
 * This area is READ ONLY on purpose. The office can look at any booking,
 * but confirming, declining, completing, and cancelling belong to the
 * resort and the guest, and BookingPolicy allows those to nobody else.
 */
class BookingController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Booking::class);

        $filters = $this->filters($request);

        // Only this table's own columns. The guest and the resort are reached by
        // eager loading rather than a join, so there is no column here to order
        // either of them by, and adding a join for it would change what every
        // other filter on this page bites on.
        $sort = TableSort::fromRequest($request, [
            'requested' => '-created_at',
            'reference' => 'booking_reference',
            'check_in' => '-check_in_date',
            'check_out' => '-check_out_date',
            'guests' => '-guests',
            'status' => 'status',
        ], default: 'requested');

        $bookings = Booking::query()
            ->with(['resort:id,name,barangay', 'room:id,name', 'tourist:id,first_name,last_name,email'])
            ->when($filters['status'], fn ($query) => $query->withStatus($filters['status']))
            ->when($filters['resort'], fn ($query) => $query->where('resort_id', $filters['resort']->id))
            ->when($filters['from'], fn ($query) => $filters['basis'] === 'requested'
                ? $query->whereDate('created_at', '>=', $filters['from'])
                : $query->where('check_out_date', '>=', $filters['from']))
            ->when($filters['to'], fn ($query) => $filters['basis'] === 'requested'
                ? $query->whereDate('created_at', '<=', $filters['to'])
                : $query->where('check_in_date', '<=', $filters['to']))
            ->when($filters['q'] !== '', fn ($query) => $query->where(function ($match) use ($filters) {
                $match->where('booking_reference', 'like', "%{$filters['q']}%")
                    ->orWhere('contact_name', 'like', "%{$filters['q']}%")
                    ->orWhereHas('tourist', fn ($tourist) => $tourist
                        ->where('first_name', 'like', "%{$filters['q']}%")
                        ->orWhere('last_name', 'like', "%{$filters['q']}%")
                        ->orWhere('email', 'like', "%{$filters['q']}%"));
            }))
            ->tap(fn ($query) => $sort->apply($query))
            ->paginate(config('visitdasol.per_page'))
            ->withQueryString();

        return view('admin.bookings.index', [
            'bookings' => $bookings,
            'filters' => $filters,
            'sort' => $sort,
            'resorts' => Resort::query()->orderBy('name')->pluck('name', 'id'),
            'counts' => Booking::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'totalNights' => (clone $bookings)->getCollection()->sum('nights'),
        ]);
    }

    public function show(Booking $booking): View
    {
        $this->authorize('view', $booking);

        $booking->load(['resort.owner', 'room', 'tourist', 'cancelledBy']);

        return view('admin.bookings.show', ['booking' => $booking]);
    }

    /**
     * Cleans the query string. Unknown values are dropped rather than
     * rejected, so a hand-edited address cannot break the page.
     *
     * @return array{status: ?BookingStatus, resort: ?Resort, from: ?string, to: ?string, q: string, basis: string}
     */
    private function filters(Request $request): array
    {
        $date = fn (mixed $value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        $basis = $request->query('basis') === 'requested' ? 'requested' : 'stay';

        return [
            'status' => BookingStatus::tryFrom((string) $request->query('status')),
            'resort' => Resort::query()->whereKey($request->query('resort'))->first(),
            'from' => $date($request->query('from')),
            'to' => $date($request->query('to')),
            'q' => trim((string) $request->query('q', '')),
            'basis' => $basis,
        ];
    }
}
