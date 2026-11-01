{{-- What ResortController@show hands this view. Spelled out because a
     Blade file carries no signature of its own: without this, a reader
     has to go and find the controller to learn that $search is an array
     and not a yes-or-no, and so does static analysis. --}}
@php
    /**
     * @var \App\Models\Resort $resort
     * @var array{
     *     check_in: ?\Carbon\CarbonImmutable,
     *     check_out: ?\Carbon\CarbonImmutable,
     *     guests: int,
     *     nights: int,
     *     error: ?string
     * } $search
     * @var list<array{room: \App\Models\Room, available: int, fits: bool}>|null $availability
     * @var \App\Models\Booking|null $activeBooking the signed-in guest's unfinished stay, if any
     * @var \Illuminate\Support\Collection $reviews
     * @var \Illuminate\Support\Collection $nearby
     */
@endphp

@extends('layouts.public')

@section('title', $resort->name)
@section('meta_description', Str::limit($resort->description, 150))

@section('content')
    <div class="container py-5">
        <div class="row g-4">
            <div class="col-lg-8">
                {{-- Resort photo gallery / slideshow --}}
                @php
                    $galleryImages = collect();

                    if (filled($resort->main_image_url)) {
                        $galleryImages->push([
                            'url' => $resort->main_image_url,
                            'alt' => $resort->name,
                        ]);
                    }

                    $galleryImages = $galleryImages
                        ->concat($resort->images->map(fn ($image) => [
                            'url' => $image->url,
                            'alt' => $image->caption ?? $resort->name,
                        ]))
                        ->filter(fn ($image) => filled($image['url']))
                        ->unique('url')
                        ->values();
                @endphp

                @if ($galleryImages->isNotEmpty())
                    <section
                        class="vd-resort-gallery"
                        data-resort-gallery
                        aria-label="{{ $resort->name }} photo gallery"
                    >
                        <div class="vd-gallery__stage">
                            @foreach ($galleryImages as $index => $image)
                                <img
                                    src="{{ $image['url'] }}"
                                    alt="{{ $image['alt'] }}"
                                    class="vd-gallery__slide {{ $index === 0 ? 'is-active' : '' }}"
                                    data-gallery-slide
                                    data-index="{{ $index }}"
                                    @if ($index !== 0) loading="lazy" @else fetchpriority="high" @endif
                                >
                            @endforeach

                            @if ($galleryImages->count() > 1)
                                <span class="vd-gallery__counter" data-gallery-counter aria-live="polite">
                                    1 / {{ $galleryImages->count() }}
                                </span>

                                <button type="button"
                                        class="vd-gallery__nav vd-gallery__prev"
                                        data-gallery-prev
                                        aria-label="Previous photo">
                                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                                </button>

                                <button type="button"
                                        class="vd-gallery__nav vd-gallery__next"
                                        data-gallery-next
                                        aria-label="Next photo">
                                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                </button>

                                <div class="vd-gallery__indicators" data-gallery-indicators>
                                    @foreach ($galleryImages as $index => $image)
                                        <button type="button"
                                                class="vd-gallery__indicator {{ $index === 0 ? 'is-active' : '' }}"
                                                data-gallery-indicator="{{ $index }}"
                                                aria-label="Show photo {{ $index + 1 }}"
                                                aria-pressed="{{ $index === 0 ? 'true' : 'false' }}">
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        @if ($galleryImages->count() > 1)
                            <div class="vd-gallery__thumbnails" aria-label="Choose a photo">
                                @foreach ($galleryImages as $index => $image)
                                    <button type="button"
                                            class="vd-gallery__thumbnail {{ $index === 0 ? 'is-active' : '' }}"
                                            data-gallery-thumbnail="{{ $index }}"
                                            aria-label="View photo {{ $index + 1 }}"
                                            aria-pressed="{{ $index === 0 ? 'true' : 'false' }}">
                                        <img src="{{ $image['url'] }}"
                                             alt="{{ $image['alt'] }}"
                                             loading="lazy">
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endif

                {{-- Resort information --}}
                <h1 class="mt-4 mb-1">{{ $resort->name }}</h1>

                <p class="text-muted-vd">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                    {{ $resort->full_address }}
                </p>

                <p class="vd-detail__lead" style="white-space: pre-line">
                    {{ $resort->description }}
                </p>

                {{-- Amenities --}}
                @if ($resort->amenities->isNotEmpty())
                    <section class="mt-4" aria-labelledby="amenities-title">
                        <h2 id="amenities-title" class="h4 mb-3">Amenities</h2>

                        <ul class="vd-amenity-list">
                            @foreach ($resort->amenities as $amenity)
                                <li>
                                    <i
                                        class="bi bi-{{ $amenity->icon ?? 'check-lg' }}"
                                        aria-hidden="true"
                                    ></i>
                                    {{ $amenity->name }}
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- Rooms and availability --}}
                <section class="mt-5" aria-labelledby="rooms-title" data-room-availability-section>
                    <h2 id="rooms-title" class="h4 mb-2">Rooms</h2>

                    <p class="text-muted-vd small">
                        Rates are set by the resort and are per room, per night.
                    </p>

                    {{-- Told here, beside the buttons it affects, rather
                         than after a form has been filled in. --}}
                    @if ($activeBooking ?? null)
                        <x-active-booking-notice :booking="$activeBooking" compact />
                    @endif

                    {{-- Availability search --}}
                    <form
                        method="GET"
                        action="{{ route('resorts.show', $resort) }}"
                        class="vd-panel p-3 mb-4"
                    >
                        <div class="row g-3 align-items-end">
                            {{-- Check-in date --}}
                            <div class="col-sm-6 col-lg-4">
                                <label class="form-label" for="check_in">
                                    Check-in Date
                                </label>

                                <input
                                    type="date"
                                    class="form-control"
                                    id="check_in"
                                    name="check_in"
                                    data-stay-check-in
                                    min="{{ now()->toDateString() }}"
                                    value="{{ $search['check_in']?->toDateString() ?? request('check_in') }}"
                                    required
                                >
                            </div>

                            {{-- Check-in time --}}
                            <div class="col-sm-6 col-lg-4">
                                <label class="form-label" for="check_in_time">
                                    Check-in Time
                                </label>

                                <input
                                    type="time"
                                    class="form-control"
                                    id="check_in_time"
                                    name="check_in_time"
                                    value="{{ request('check_in_time', '14:00') }}"
                                    required
                                >

                                <small class="text-muted-vd">
                                    Resort's usual check-in: {{ \Illuminate\Support\Carbon::parse($resort->check_in_time)->format('g:i A') }}
                                </small>
                            </div>

                            {{-- Check-out date --}}
                            <div class="col-sm-6 col-lg-4">
                                <label class="form-label" for="check_out">
                                    Check-out Date
                                </label>

                                <input
                                    type="date"
                                    class="form-control"
                                    id="check_out"
                                    name="check_out"
                                    data-stay-check-out
                                    min="{{ $search['check_in']
                                        ? $search['check_in']->copy()->addDay()->toDateString()
                                        : now()->addDay()->toDateString() }}"
                                    value="{{ $search['check_out']?->toDateString() ?? request('check_out') }}"
                                    required
                                >
                            </div>

                            {{-- Check-out time --}}
                            <div class="col-sm-6 col-lg-4">
                                <label class="form-label" for="check_out_time">
                                    Check-out Time
                                </label>

                                <input
                                    type="time"
                                    class="form-control"
                                    id="check_out_time"
                                    name="check_out_time"
                                    value="{{ request('check_out_time', '12:00') }}"
                                    required
                                >

                                <small class="text-muted-vd">
                                    Resort's usual check-out: {{ \Illuminate\Support\Carbon::parse($resort->check_out_time)->format('g:i A') }}
                                </small>
                            </div>

                            {{-- Guests --}}
                            <div class="col-sm-6 col-lg-4">
                                <label class="form-label" for="guests">
                                    Guests
                                </label>

                                <input
                                    type="number"
                                    class="form-control"
                                    id="guests"
                                    name="guests"
                                    min="1"
                                    max="60"
                                    value="{{ $search['guests'] }}"
                                    required
                                >
                            </div>

                            {{-- Submit --}}
                            <div class="col-sm-6 col-lg-4 d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>
                                    Check Availability
                                </button>
                            </div>
                        </div>
                    </form>

                    {{-- Search result messages --}}
                    @if ($search['error'])
                        <div class="alert alert-warning" role="status">
                            {{ $search['error'] }}
                        </div>
                    @elseif ($availability !== null)
                        <div class="vd-panel p-3 mb-4">
                            <h3 class="h6 mb-2">Your selected stay</h3>

                            <p class="text-muted-vd mb-1">
                                <i class="bi bi-calendar-event me-1" aria-hidden="true"></i>
                                {{ $search['check_in']->format('M j, Y') }}
                                at {{ \Illuminate\Support\Carbon::parse(request('check_in_time', '14:00'))->format('g:i A') }}
                            </p>

                            <p class="text-muted-vd mb-1">
                                <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>
                                {{ $search['check_out']->format('M j, Y') }}
                                at {{ \Illuminate\Support\Carbon::parse(request('check_out_time', '12:00'))->format('g:i A') }}
                            </p>

                            <p class="text-muted-vd mb-0">
                                {{ $search['nights'] }}
                                {{ Str::plural('night', $search['nights']) }}
                                · {{ $search['guests'] }}
                                {{ Str::plural('guest', $search['guests']) }}
                            </p>
                        </div>
                    @endif

                    {{-- Room list --}}
                    @if ($resort->rooms->isEmpty())
                        <x-empty-state
                            icon="door-closed"
                            title="No rooms listed yet"
                            message="This resort has not published its room types. Contact them directly for rates."
                        />
                    @else
                        <div class="row g-3">
                            @foreach (
                                $availability ?? $resort->rooms->map(
                                    fn ($room) => [
                                        'room' => $room,
                                        'available' => null,
                                        'fits' => true,
                                    ]
                                ) as $entry
                            )
                                @php
                                    $room = $entry['room'];
                                @endphp

                                <div class="col-md-6">
                                    <article class="vd-panel p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <div>
                                                <h3 class="h5 mb-1">
                                                    {{ $room->name }}
                                                </h3>

                                                <p class="text-muted-vd small mb-2">
                                                    {{ $room->room_type->label() }}
                                                    · Up to {{ $room->capacity }}
                                                    {{ Str::plural('guest', $room->capacity) }}
                                                    per room
                                                    · {{ $room->bed_count }}
                                                    {{ Str::plural('bed', $room->bed_count) }}
                                                </p>

                                                {{-- Worth knowing before choosing a room rather
                                                     than on the booking form, because it is one
                                                     of the few things that tells two rooms at
                                                     the same rate apart. --}}
                                                @if ($room->hasChildDiscount() || $room->hasPwdDiscount())
                                                    <p class="mb-2 small">
                                                        @if ($room->hasChildDiscount())
                                                            <span class="badge text-bg-light border me-1">
                                                                <i class="bi bi-tag" aria-hidden="true"></i>
                                                                {{ $room->child_discount_percent }}% off children {{ $room->childAgeRange() }}
                                                            </span>
                                                        @endif
                                                        @if ($room->hasPwdDiscount())
                                                            <span class="badge text-bg-light border">
                                                                <i class="bi bi-tag" aria-hidden="true"></i>
                                                                {{ $room->pwd_discount_percent }}% off PWD guests
                                                            </span>
                                                        @endif
                                                    </p>
                                                @endif
                                            </div>

                                            <div class="text-end">
                                                <strong class="vd-card__price d-block">
                                                    @peso($room->price_per_night)
                                                </strong>
                                                <span class="text-muted-vd small">
                                                    per night
                                                </span>

                                                {{-- The tour rates underneath, when the resort
                                                     runs them. Priced for the whole booking, not
                                                     per night, so they are not labelled as a
                                                     nightly rate. --}}
                                                @foreach ($room->bookingTypes() as $case)
                                                    @continue ($case->isOvernight())
                                                    <span class="d-block small text-muted-vd">
                                                        <i class="bi bi-{{ $case->icon() }}" aria-hidden="true"></i>
                                                        {{ $case->label() }} @peso($room->priceFor($case))
                                                    </span>
                                                @endforeach
                                            </div>
                                        </div>

                                        {{-- Room images --}}
                                        @if ($room->images->isNotEmpty())
                                            <ul class="vd-gallery__strip mb-3">
                                                @foreach ($room->images->take(3) as $image)
                                                    <li>
                                                        <img
                                                            src="{{ $image->url }}"
                                                            alt="{{ $image->caption ?? $room->name }}"
                                                            loading="lazy"
                                                        >
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <p class="text-muted-vd small mb-3">
                                                <i class="bi bi-camera" aria-hidden="true"></i>
                                                No photo of this room yet
                                            </p>
                                        @endif

                                        @if (filled($room->description))
                                            <p class="text-muted-vd small mb-3">
                                                {{ $room->description }}
                                            </p>
                                        @endif

                                        {{-- No dates selected --}}
                                        @if ($entry['available'] === null)
                                            <p class="small text-muted-vd mb-2">
                                                Choose your dates above to see what is free.
                                            </p>

                                            <a
                                                class="btn btn-outline-primary btn-sm js-check-room-availability"
                                                data-loading-label="Checking availability..."
                                                href="{{ route('resorts.show', [
                                                    $resort,
                                                    'check_in' => now()->addDay()->toDateString(),
                                                    'check_in_time' => request('check_in_time', '14:00'),
                                                    'check_out' => now()->addDays(3)->toDateString(),
                                                    'check_out_time' => request('check_out_time', '12:00'),
                                                    'guests' => request('guests', 2),
                                                ]) }}"
                                            >
                                                Check availability
                                            </a>

                                        {{-- Room capacity --}}
                                        @elseif (! $entry['fits'])
                                            <p class="small text-muted-vd mb-0">
                                                <i class="bi bi-people me-1" aria-hidden="true"></i>
                                                Takes up to {{ $room->capacity }}
                                                {{ Str::plural('guest', $room->capacity) }}.
                                                Book more than one room, or choose a larger type.
                                            </p>

                                        {{-- No available rooms --}}
                                        @elseif ($entry['available'] < 1)
                                            <p class="small mb-0">
                                                <span class="vd-badge vd-badge--inactive">
                                                    Fully booked for these dates
                                                </span>
                                            </p>

                                        {{-- Available room --}}
                                        @else
                                            <p class="small mb-2">
                                                <span class="vd-badge vd-badge--active">{{ $entry['available'] }} {{ Str::plural('room', $entry['available']) }} free</span>

                                                <span class="text-muted-vd ms-1">
                                                    Total from
                                                    @peso((float) $room->price_per_night * $search['nights'])
                                                    for {{ $search['nights'] }}
                                                    {{ Str::plural('night', $search['nights']) }}
                                                </span>
                                            </p>

                                            {{-- A guest who already holds a reservation
                                                 cannot finish another, so the button says
                                                 so rather than leading them to a wall.
                                                 Still a link, not a dead button: the page
                                                 it goes to is where they can see and call
                                                 off the booking in the way. --}}
                                            @if ($activeBooking ?? null)
                                                <a
                                                    class="btn btn-outline-secondary btn-sm"
                                                    href="{{ route('tourist.bookings.show', $activeBooking) }}"
                                                >
                                                    <i class="bi bi-lock me-1" aria-hidden="true"></i>
                                                    Finish your other booking first
                                                </a>
                                            @else
                                                {{-- Pass dates, times, and guests to booking form --}}
                                                <a
                                                    class="btn btn-accent btn-sm"
                                                    href="{{ route('tourist.bookings.create', [
                                                        'room' => $room->id,
                                                        'check_in' => $search['check_in']->toDateString(),
                                                        'check_in_time' => request('check_in_time', '14:00'),
                                                        'check_out' => $search['check_out']->toDateString(),
                                                        'check_out_time' => request('check_out_time', '12:00'),
                                                        'guests' => $search['guests'],
                                                    ]) }}"
                                                >
                                                    <i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>
                                                    Request this room
                                                </a>
                                            @endif
                                        @endif
                                    </article>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                {{-- Guest reviews --}}
                <section class="mt-5" aria-labelledby="reviews-title">
                    <div class="d-flex flex-wrap align-items-baseline gap-3 mb-3">
                        <h2 id="reviews-title" class="h4 mb-0">Guest reviews</h2>

                        @if ($resort->rating_count > 0)
                            <x-stars
                                :rating="$resort->rating_average"
                                :count="$resort->rating_count"
                            />
                        @endif
                    </div>

                    <p class="text-muted-vd small">
                        Only guests who actually stayed here can write a review,
                        and only once per stay.
                    </p>

                    <x-review-list :reviews="$reviews" />
                </section>
            </div>

            {{-- Resort sidebar --}}
            <div class="col-lg-4">
                {{-- Ask before booking.
                     Only a signed-in tourist: the policy refuses an owner
                     or the office, and a signed-out visitor would be
                     offered a button that could only bounce them to the
                     sign-in page. POST, because it can open a thread. --}}
                @auth
                    @if (auth()->user()->isTourist() && Route::has('messages.start'))
                        <form class="mb-4" method="POST"
                              action="{{ route('messages.start', $resort) }}">
                            @csrf
                            <button class="btn btn-outline-primary w-100" type="submit">
                                <i class="bi bi-chat-dots me-1" aria-hidden="true"></i>Message this resort
                            </button>
                        </form>
                    @endif
                @endauth

                <aside class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-3">Good to know</h2>

                   
                    

                        <dt class="col-5 text-muted-vd fw-normal">Contact</dt>
                        <dd class="col-7">{{ $resort->contact_number }}</dd>

                        @if (filled($resort->email))
                            <dt class="col-5 text-muted-vd fw-normal">Email</dt>
                            <dd class="col-7 text-break">
                                <a href="mailto:{{ $resort->email }}">
                                    {{ $resort->email }}
                                </a>
                            </dd>
                        @endif

                        @if (filled($resort->website_url))
                            <dt class="col-5 text-muted-vd fw-normal">Website</dt>
                            <dd class="col-7 text-break">
                                <x-external-link
                                    :href="$resort->website_url"
                                    rel="noopener nofollow"
                                >
                                    Visit page
                                </x-external-link>
                            </dd>
                        @endif
                    </dl>
                </aside>

                {{-- Location --}}
                <aside class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-2">Location</h2>

                    <p class="text-muted-vd small">
                        {{ $resort->full_address }}
                    </p>

                    <x-location-map
                        :latitude="$resort->latitude"
                        :longitude="$resort->longitude"
                        :label="$resort->name"
                        style="height: 15rem"
                        class="mb-2"
                    />

                    <div class="d-flex flex-wrap gap-2">
                        <x-external-link
                            class="btn btn-outline-primary btn-sm"
                            href="https://www.openstreetmap.org/?mlat={{ $resort->latitude }}&mlon={{ $resort->longitude }}#map=16/{{ $resort->latitude }}/{{ $resort->longitude }}"
                        >
                            <i class="bi bi-compass me-1" aria-hidden="true"></i>
                            Get directions
                        </x-external-link>

                        <a class="btn btn-light border btn-sm" href="{{ route('map') }}">
                            See all on the map
                        </a>
                    </div>
                </aside>
            </div>
        </div>

        {{-- Nearby resorts --}}
        @if ($nearby->isNotEmpty())
            <section class="mt-5" aria-labelledby="nearby-title">
                <h2 id="nearby-title" class="h4 mb-3">
                    Other resorts in Dasol
                </h2>

                <div class="row g-4">
                    @foreach ($nearby as $other)
                        <div class="col-md-6 col-lg-4">
                            <x-resort-card :resort="$other" />
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    {{-- Keeping the check-out after the check-in is handled by app.js,
         hung off data-stay-check-in and data-stay-check-out on the two
         fields above. It was an inline <script> here, which the Content
         Security Policy blocks — so it never ran, and the form took a
         check-out before the arrival until the server refused it. --}}
@endsection
