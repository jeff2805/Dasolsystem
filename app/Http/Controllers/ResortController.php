<?php

namespace App\Http\Controllers;

use App\Enums\RoomType;
use App\Models\Amenity;
use App\Models\Resort;
use App\Models\Room;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Public resort listing and detail pages.
 *
 * Both start from Resort::publiclyVisible(), which means an active resort
 * whose owner account is also active. Pending, rejected, inactive, and
 * hidden-owner listings are never reachable, not even by guessing a slug.
 */
class ResortController extends Controller
{
    private const SORTS = ['recommended', 'price_low', 'price_high', 'name'];

    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * What a visitor is actually choosing between: rooms.
     *
     * This page used to list resorts, which meant the thing a guest books —
     * a room type, at a price, for a number of people — was one click away and
     * invisible until they got there. Two resorts looked alike on the listing
     * even when one had a ₱600 room and the other started at ₱6,500.
     *
     * So the row is a room, and it carries its resort with it. Every filter
     * now bites on the thing it names: "up to ₱2,000" hides the expensive
     * rooms rather than keeping a resort because something cheap exists
     * somewhere inside it.
     *
     * The query joins resorts because it sorts and filters on both tables.
     * Every column below is therefore qualified with its table: rooms and
     * resorts share name, description, status and created_at, and an
     * unqualified one would make the whole query ambiguous and fail.
     */
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $rooms = Room::query()
            ->bookable()
            ->join('resorts', 'resorts.id', '=', 'rooms.resort_id')
            ->select('rooms.*')
            ->with(['resort.amenities' => fn (Builder $amenity) => $amenity->where('amenities.is_active', true), 'images'])
            ->when($filters['q'] !== '', function (Builder $query) use ($filters) {
                $query->where(function (Builder $match) use ($filters) {
                    $match->where('rooms.name', 'like', "%{$filters['q']}%")
                        ->orWhere('rooms.description', 'like', "%{$filters['q']}%")
                        ->orWhere('resorts.name', 'like', "%{$filters['q']}%")
                        ->orWhere('resorts.barangay', 'like', "%{$filters['q']}%");
                });
            })
            ->when($filters['barangay'], fn (Builder $query) => $query->where('resorts.barangay', $filters['barangay']))
            ->when($filters['type'], fn (Builder $query) => $query->where('rooms.room_type', $filters['type']))
            ->when($filters['guests'], fn (Builder $query) => $query->where('rooms.capacity', '>=', $filters['guests']))
            ->when($filters['max_price'], fn (Builder $query) => $query->where('rooms.price_per_night', '<=', $filters['max_price']));

        // Every chosen amenity must be present, not just one of them. Amenities
        // belong to the resort, so this asks about the room's resort.
        foreach ($filters['amenities'] as $amenityName) {
            $rooms->whereHas('resort.amenities', fn (Builder $amenity) => $amenity->where('amenities.name', $amenityName)->where('amenities.is_active', true));
        }

        match ($filters['sort']) {
            'price_low' => $rooms->orderBy('rooms.price_per_night'),
            'price_high' => $rooms->orderByDesc('rooms.price_per_night'),
            'name' => $rooms->orderBy('resorts.name')->orderBy('rooms.name'),
            default => $rooms->orderByDesc('resorts.is_featured')->orderBy('rooms.price_per_night'),
        };

        // Settled before pagination, so the heading describes the whole result
        // and not just the page being looked at.
        $resortCount = (clone $rooms)->distinct()->count('rooms.resort_id');

        $page = $rooms->paginate(config('visitdasol.per_page'))->withQueryString();

        /*
         * How many units of each room are free, when the visitor named dates.
         *
         * Worked out for the rooms on THIS page only, in one query, so the
         * cost does not grow with the number of resorts. Dates annotate the
         * listing rather than filter it: a visitor is better served seeing
         * that a room they liked is full on those nights than having it
         * vanish with no explanation.
         */
        $availability = $filters['nights'] > 0
            ? $this->availability->availableUnitsForRooms($page->items(), $filters['check_in'], $filters['check_out'])
            : null;

        return view('site.resorts.index', [
            'rooms' => $page,
            'availability' => $availability,
            'resortCount' => $resortCount,
            'filters' => $filters,
            'barangays' => config('visitdasol.barangays'),
            'roomTypes' => RoomType::options(),
            'amenities' => Amenity::query()->active()
                ->selectRaw('MIN(id) as id, name, icon')
                ->groupBy('name', 'icon')
                ->orderBy('name')->get(),
            'sorts' => [
                'recommended' => 'Recommended',
                'price_low' => 'Price, low to high',
                'price_high' => 'Price, high to low',
                'name' => 'Resort name (A to Z)',
            ],
        ]);
    }

    public function show(Request $request, Resort $resort): View
    {
        abort_unless($resort->isPubliclyVisible(), 404);

        $resort->load([
            'images',
            'amenities' => fn ($amenity) => $amenity->where('amenities.is_active', true),
            'rooms' => fn ($query) => $query->active()->with('images')->orderBy('price_per_night'),
        ]);

        // Hidden reviews are left out of both the list and the average, so a
        // moderated review stops counting the moment it is taken down.
        $resort->loadCount(['reviews as rating_count' => fn ($query) => $query->published()])
            ->loadAvg(['reviews as rating_average' => fn ($query) => $query->published()], 'rating');

        // Optional availability search: ?check_in=&check_out=&guests=
        $search = $this->stayFromRequest($request);
        $rooms = $search['nights'] > 0
            ? $this->availability->forResort($resort, $search['check_in'], $search['check_out'], $search['guests'])
            : null;

        /*
         * A guest who already holds a reservation elsewhere cannot make
         * another. Told here, on the page with the "Request this room"
         * buttons, rather than only once they have filled a form in.
         */
        $activeBooking = $request->user()?->isTourist()
            ? app(BookingService::class)->activeBookingFor($request->user())
            : null;

        return view('site.resorts.show', [
            'resort' => $resort,
            'search' => $search,
            'availability' => $rooms,
            'activeBooking' => $activeBooking,
            'reviews' => $resort->reviews()
                ->published()
                ->with('author:id,first_name,last_name')
                ->latest()
                ->limit(10)
                ->get(),
            'nearby' => Resort::publiclyVisible()
                ->whereKeyNot($resort->id)
                ->with(['amenities' => fn (Builder $amenity) => $amenity->where('amenities.is_active', true)])
                ->withMin(['rooms as starting_price' => fn ($query) => $query->active()], 'price_per_night')
                ->orderByRaw('CASE WHEN barangay = ? THEN 0 ELSE 1 END', [$resort->barangay])
                ->orderByDesc('is_featured')
                ->limit(3)
                ->get(),
        ]);
    }

    /**
     * Reads the check-in, check-out, and guest count from the query string.
     * Anything unusable is ignored, and the page simply shows no
     * availability rather than an error.
     *
     * @return array{check_in: ?CarbonImmutable, check_out: ?CarbonImmutable, guests: int, nights: int, error: ?string}
     */
    private function stayFromRequest(Request $request): array
    {
        $parse = function (mixed $value): ?CarbonImmutable {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }

            try {
                return CarbonImmutable::parse($value)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $checkIn = $parse($request->query('check_in'));
        $checkOut = $parse($request->query('check_out'));
        $guests = max(1, min(60, (int) $request->query('guests', 1)));
        $error = null;
        $nights = 0;

        if ($checkIn && $checkOut) {
            if ($checkIn->lt(CarbonImmutable::today())) {
                $error = 'Choose a check-in date from today onwards.';
            } elseif ($checkOut->lte($checkIn)) {
                $error = 'The check-out date must be after the check-in date.';
            } else {
                $nights = (int) $checkIn->diffInDays($checkOut);

                if ($nights > config('visitdasol.booking.max_nights')) {
                    $error = 'Stays longer than '.config('visitdasol.booking.max_nights').' nights cannot be booked online.';
                    $nights = 0;
                }
            }
        }

        return [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guests' => $guests,
            'nights' => $nights,
            'error' => $error,
        ];
    }

    /**
     * Cleans the query string. Unknown values are dropped rather than
     * rejected, so a hand-edited URL can never break the page or the query.
     *
     * @return array{q: string, barangay: ?string, type: ?string, guests: ?int, max_price: ?int, amenities: list<int>, sort: string, check_in: ?CarbonImmutable, check_out: ?CarbonImmutable, nights: int, error: ?string}
     */
    private function filters(Request $request): array
    {
        $barangay = $request->query('barangay');
        $type = $request->query('type');
        $sort = (string) $request->query('sort', 'recommended');

        // The same reading of check_in / check_out / guests the resort page
        // uses, so a search made on one works unchanged on the other.
        $stay = $this->stayFromRequest($request);

        $amenities = collect((array) $request->query('amenities', []))
            ->map(function ($value) {
                if (is_numeric($value)) {
                    return Amenity::query()->whereKey((int) $value)->value('name');
                }
                return is_string($value) ? trim($value) : null;
            })
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'q' => trim((string) $request->query('q', '')),
            'barangay' => in_array($barangay, config('visitdasol.barangays'), true) ? $barangay : null,
            'type' => in_array($type, RoomType::values(), true) ? $type : null,
            'guests' => max(0, min(30, (int) $request->query('guests'))) ?: null,
            'max_price' => max(0, min(1_000_000, (int) $request->query('max_price'))) ?: null,
            'amenities' => $amenities,
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'recommended',

            'check_in' => $stay['check_in'],
            'check_out' => $stay['check_out'],
            'nights' => $stay['nights'],
            'error' => $stay['error'],
        ];
    }
}
