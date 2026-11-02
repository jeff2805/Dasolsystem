@extends('layouts.public')

@section('title', 'GIS Map')
@section('meta_description', 'Map of resorts and destinations in Dasol, Pangasinan.')

{{-- The dashboard around the map: the search box, the filters, the
     barangay panel and the bigger view. Only this page has them, so only
     this page loads it. map.js itself is pushed by <x-visit-map>, and
     comes first because gis.js listens to the events it fires. --}}
@push('scripts')
    <script src="@vdasset('js/gis.js')" defer></script>
@endpush

@section('content')
    {{-- Full width, unlike every other public page.

         A map is the one thing on this site that is worth more the bigger
         it is, and three panes inside the usual 1140px container would
         leave the map itself about as wide as a phone. The site's own
         navigation and footer stay: this is a page of VisitDasol, not a
         separate application, and the skip link and flash messages live
         in that layout. --}}
    <div class="vd-gis">
        {{-- Heading strip. The counts are the real ones; "13 of the 18"
             is said out loud rather than rounded up to eighteen. --}}
        <header class="vd-gis__head">
            <div class="vd-gis__title">
                <h1>
                    <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                    Map of Dasol
                </h1>
                <p class="vd-gis__sub">
                    Explore Dasol, Pangasinan &mdash;
                    {{ $resorts->count() }} {{ Str::plural('resort', $resorts->count()) }},
                    {{ $destinations->count() }} {{ Str::plural('destination', $destinations->count()) }},
                    and {{ $barangays->count() }} of the 18 {{ Str::plural('barangay', $barangays->count()) }} on the map.
                </p>
            </div>

            <nav class="vd-gis__links" aria-label="Related pages">
                <a href="{{ route('resorts.index') }}">
                    <i class="bi bi-house-heart" aria-hidden="true"></i>Resorts
                </a>
                <a href="{{ route('destinations.index') }}">
                    <i class="bi bi-signpost-2" aria-hidden="true"></i>Destinations
                </a>
            </nav>
        </header>

        @if ($points->isEmpty() && $barangays->isEmpty())
            {{-- The barangay names are worth a map on their own, so a
                 municipality with no listings yet still gets one. --}}
            <x-empty-state icon="map" title="Nothing on the map yet"
                           message="Approved resorts and published destinations appear here with their locations." />
        @else
            @php
                $places = [
                    'Resorts' => $resorts,
                    'Destinations' => $destinations,
                    'Barangays' => $barangays->map(fn ($b) => ['id' => $b['id'], 'name' => 'Brgy. '.$b['name']]),
                ];
                // Without a search service the "From" box can still name the
                // places on this map, and says only that.
                $hasPlaceSearch = filled(config('visitdasol.map.search_url'));
            @endphp

            {{-- Three panes: the places, the map, and what you have picked.

                 data-map-scope tells map.js to look for the controls anywhere
                 inside this wrapper rather than only beside the map, which is
                 what lets them sit in the pane they belong to. --}}
            <div class="vd-gis__stage vd-mapstage" data-map-scope data-gis>

                {{-- LEFT: search, filters, and the places on the map ---------- --}}
                <aside class="vd-gis__pane vd-gis__pane--list" aria-label="Places on the map">
                    <div class="vd-gis__search">
                        <label class="visually-hidden" for="gis-search">Search the map</label>
                        <div class="vd-gis__searchbox">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <input type="search" id="gis-search" class="form-control"
                                   placeholder="Search resorts, beaches, barangays..."
                                   autocomplete="off" data-gis-search>
                        </div>

                        {{-- Filters, not tabs: each one narrows the same list
                             below rather than opening a different screen. --}}
                        <div class="vd-gis__filters" role="group" aria-label="Show only">
                            <button type="button" class="vd-gis__filter is-on"
                                    data-gis-filter="all" aria-pressed="true">All</button>
                            <button type="button" class="vd-gis__filter"
                                    data-gis-filter="resort" aria-pressed="false">
                                <i class="bi bi-house-heart" aria-hidden="true"></i>Resorts
                            </button>
                            <button type="button" class="vd-gis__filter"
                                    data-gis-filter="destination" aria-pressed="false">
                                <i class="bi bi-signpost-2" aria-hidden="true"></i>Destinations
                            </button>
                            <button type="button" class="vd-gis__filter"
                                    data-gis-filter="barangay" aria-pressed="false">
                                <i class="bi bi-pin-map" aria-hidden="true"></i>Barangays
                            </button>
                        </div>

                        {{-- Set when a barangay is picked, so the list says
                             what it is currently narrowed to and offers a way
                             back out. --}}
                        <p class="vd-gis__scopenote" data-gis-scope-note hidden>
                            <span data-gis-scope-label></span>
                            <button type="button" class="btn btn-sm btn-link p-0" data-gis-scope-clear>
                                Show all
                            </button>
                        </p>
                    </div>

                    {{-- "My location" belongs with the list, because the list is
                         what it changes: every row gains a distance. Keeping it
                         off the map also keeps Leaflet's own controls and the
                         OpenStreetMap credit unobstructed. --}}
                    <div class="vd-mapstage__tools">
                        <button type="button" class="btn btn-sm btn-primary w-100" data-map-locate hidden>
                            <i class="bi bi-crosshair me-1" aria-hidden="true"></i>My location
                        </button>

                        {{-- Appears only once a pin has been dragged. On a
                             laptop the browser is wrong the same way every
                             visit, because it is placed by the networks around
                             it and those do not move — so the correction is
                             worth keeping. Offered, never applied on its own:
                             where somebody stood last month is not where they
                             are standing now. --}}
                        <button type="button" class="btn btn-sm btn-outline-primary w-100"
                                data-map-saved-place hidden>
                            <i class="bi bi-pin-angle me-1" aria-hidden="true"></i>Use the spot I set
                        </button>

                        <button type="button" class="btn btn-sm btn-link p-0 text-start"
                                data-map-forget-place hidden>
                            Forget that spot
                        </button>

                        {{-- Keeping up with somebody who is moving.
                             Off until asked: a watch left running keeps the
                             GPS warm and costs battery, which is not a thing
                             to switch on behind a visitor's back. Stops by
                             itself when the route is cleared. --}}
                        <button type="button" class="btn btn-sm btn-outline-primary w-100"
                                data-map-follow aria-pressed="false" hidden>
                            <i class="bi bi-broadcast me-1" aria-hidden="true"></i>Follow my location
                        </button>

                        {{-- Only once there is a line on the map. A route used
                             to stay until another replaced it, so a visitor who
                             wanted to read the coast underneath it could not
                             put it away. --}}
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100"
                                data-map-clear-route hidden>
                            <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear route
                        </button>

                        <p class="vd-map-readout mb-0" data-map-readout role="status" hidden></p>

                        {{-- Said before the button is pressed, not after it
                             gets it wrong. A laptop is placed by the wifi it
                             can see, which around here is often the wrong
                             barangay, and somebody who knows that in advance
                             reads the pin correctly the first time. --}}
                        <p class="vd-map-hint mb-0">
                            <strong>A laptop cannot find you precisely.</strong> It has no GPS, so the
                            browser places it by nearby wi-fi &mdash; and where few networks are known,
                            it falls back to your internet connection, which points at your provider and
                            can be in another province. A phone outdoors is accurate to a few metres.
                            Either way: drag the blue pin to where you really are, and it is saved for
                            next time.
                        </p>
                    </div>

                    <div class="vd-mapstage__scroll" data-gis-list>
                        <h2 class="vd-mapstage__group" data-gis-group="resort">Resorts</h2>
                        @forelse ($resorts as $resort)
                            <button type="button" class="vd-map-list__item vd-gis__card"
                                    data-map-focus="{{ $resort['id'] }}"
                                    data-gis-item="resort"
                                    data-gis-barangay="{{ $resort['barangay'] }}"
                                    data-gis-name="{{ Str::lower($resort['name'].' '.$resort['barangay']) }}">
                                <img src="{{ $resort['image'] }}" alt="" loading="lazy">
                                <span>
                                    <span class="d-block fw-semibold">{{ $resort['name'] }}</span>
                                    <span class="small text-muted-vd">{{ $resort['subtitle'] }}</span>

                                    {{-- Price and score only where there is one.
                                         A blank line is honest; "₱0" and "0.0"
                                         are not. --}}
                                    <span class="vd-gis__card-meta">
                                        @if ($resort['price_from'] !== null)
                                            <span class="vd-gis__price">
                                                @peso($resort['price_from'])<span class="vd-gis__per"> / night</span>
                                            </span>
                                        @endif
                                        @if ($resort['rating'] !== null)
                                            <span class="vd-gis__rating">
                                                <i class="bi bi-star-fill" aria-hidden="true"></i>{{ $resort['rating'] }}
                                                <span class="text-muted-vd">({{ $resort['rating_count'] }})</span>
                                            </span>
                                        @endif
                                    </span>

                                    {{-- Painted by the server for the first
                                         view, then repainted by map.js when the
                                         visitor names other nights. --}}
                                    @if (($resort['occupancy']['status'] ?? null) !== null)
                                        <span class="vd-gis__status vd-gis__status--{{ $resort['occupancy']['status'] }}"
                                              data-gis-status="{{ $resort['id'] }}">
                                            {{-- The same four words map.js uses in
                                                 OCCUPANCY_WORD. Keep them in step. --}}
                                            {{ match ($resort['occupancy']['status']) {
                                                'available' => 'Rooms free',
                                                'busy' => 'Filling up',
                                                'full' => 'Fully booked',
                                                default => 'Availability not published',
                                            } }}
                                        </span>
                                    @endif

                                    {{-- Filled in once the visitor shares a location. --}}
                                    <span class="vd-map-list__away" data-map-distance="{{ $resort['id'] }}" hidden></span>
                                </span>
                            </button>
                        @empty
                            <p class="vd-mapstage__none" data-gis-group="resort">No resorts on the map yet.</p>
                        @endforelse

                        <h2 class="vd-mapstage__group" data-gis-group="destination">Destinations</h2>
                        @forelse ($destinations as $destination)
                            <button type="button" class="vd-map-list__item vd-gis__card"
                                    data-map-focus="{{ $destination['id'] }}"
                                    data-gis-item="destination"
                                    data-gis-barangay="{{ $destination['barangay'] }}"
                                    data-gis-name="{{ Str::lower($destination['name'].' '.$destination['subtitle']) }}">
                                <img src="{{ $destination['image'] }}" alt="" loading="lazy">
                                <span>
                                    <span class="d-block fw-semibold">{{ $destination['name'] }}</span>
                                    <span class="small text-muted-vd">{{ $destination['subtitle'] }}</span>
                                    <span class="vd-map-list__away" data-map-distance="{{ $destination['id'] }}" hidden></span>
                                </span>
                            </button>
                        @empty
                            <p class="vd-mapstage__none" data-gis-group="destination">No destinations on the map yet.</p>
                        @endforelse

                        {{-- Barangays are rows here too, so the search box can
                             answer "where is Tambobong" and not only "which
                             resort is in Tambobong". --}}
                        <h2 class="vd-mapstage__group" data-gis-group="barangay">Barangays</h2>
                        @forelse ($barangays as $barangay)
                            <button type="button" class="vd-map-list__item vd-gis__card vd-gis__card--plain"
                                    data-map-focus="{{ $barangay['id'] }}"
                                    data-gis-item="barangay"
                                    data-gis-barangay="{{ $barangay['name'] }}"
                                    data-gis-name="{{ Str::lower('brgy '.$barangay['name']) }}">
                                <span class="vd-gis__brgyicon" aria-hidden="true">
                                    <i class="bi bi-pin-map-fill"></i>
                                </span>
                                <span>
                                    <span class="d-block fw-semibold">Brgy. {{ $barangay['name'] }}</span>
                                    <span class="small text-muted-vd">
                                        {{ $barangayFacts[$barangay['name']]['resorts'] ?? 0 }}
                                        {{ Str::plural('resort', $barangayFacts[$barangay['name']]['resorts'] ?? 0) }},
                                        {{ $barangayFacts[$barangay['name']]['destinations'] ?? 0 }}
                                        {{ Str::plural('spot', $barangayFacts[$barangay['name']]['destinations'] ?? 0) }}
                                    </span>
                                </span>
                            </button>
                        @empty
                            <p class="vd-mapstage__none" data-gis-group="barangay">No barangay points yet.</p>
                        @endforelse

                        {{-- Shown only when the search clears the list out. --}}
                        <p class="vd-mapstage__none" data-gis-empty hidden>
                            Nothing here matches that. Try a resort, a beach, or a barangay name.
                        </p>
                    </div>
                </aside>

                {{-- CENTRE: the map ------------------------------------------- --}}
                <div class="vd-gis__pane vd-gis__pane--map">
                    {{-- The check-in and check-out pickers stood here.

                         They turned the pins into a forecast for chosen
                         nights. Removed from the map on request; the
                         pins still show TONIGHT, which the server works
                         out and sends with each point, so the colours and
                         the legend below are unchanged.

                         Choosing other nights now belongs to the resort
                         pages and the stay search, which is where a
                         visitor goes on to book anyway. --}}
                    <div class="vd-gis__canvaswrap">
                        <x-visit-map :points="$points" :barangays="$barangays"
                                     :fuel="$fuel" :barangay-shapes="$barangayShapes"
                                     class="vd-gis__canvas" :locate="true"
                                     :occupancy="route('map.occupancy')" />

                        {{-- Bigger view. Uses the browser's own fullscreen, so
                             Escape leaves it the way a visitor expects.

                             Hidden until gis.js has checked the browser
                             will do it: a button that does nothing is
                             worse than no button. --}}
                        <button type="button" class="vd-gis__expand" data-gis-fullscreen
                                aria-pressed="false" hidden>
                            <i class="bi bi-arrows-fullscreen" aria-hidden="true"></i>
                            <span class="visually-hidden">Bigger view</span>
                        </button>
                    </div>

                    {{-- A key is a list of things, so it is marked up as one. --}}
                    <details class="vd-gis__legend">
                        <summary>Map key</summary>
                        <ul class="vd-map-legend mb-0">
                            <li>
                                <span class="vd-pin vd-pin--resort"><i class="bi bi-house-heart-fill" aria-hidden="true"></i></span>
                                Resorts
                            </li>
                            <li>
                                <span class="vd-pin vd-pin--destination"><i class="bi bi-signpost-2-fill" aria-hidden="true"></i></span>
                                Destinations
                            </li>
                            @if ($barangayShapes)
                                <li>
                                    <span class="vd-legend-shape" aria-hidden="true"></span>
                                    Barangay boundaries &mdash; tap one to pick it out
                                </li>
                            @endif

                            @if ($barangays->isNotEmpty())
                                <li>
                                    <span class="vd-brgy vd-brgy--approx position-static d-inline-block">Barangay</span>
                                    Barangay names
                                </li>
                            @endif

                            {{-- Only listed when there are any. A key entry for a layer
                                 that is not on the map explains nothing. --}}
                            @if (count($fuel))
                                <li>
                                    <span class="vd-pin vd-pin--fuel"><i class="bi bi-fuel-pump-fill" aria-hidden="true"></i></span>
                                    Filling stations
                                </li>
                            @endif

                            {{-- What the resort pins' colours mean. Named as well as
                                 coloured, because colour alone is not an answer for a
                                 visitor who cannot tell green from orange. --}}
                            <li>
                                <span class="vd-pin vd-pin--resort vd-pin--available"><i class="bi bi-house-heart-fill" aria-hidden="true"></i></span>
                                Rooms free
                            </li>
                            <li>
                                <span class="vd-pin vd-pin--resort vd-pin--busy"><i class="bi bi-house-heart-fill" aria-hidden="true"></i></span>
                                Filling up
                            </li>
                            <li>
                                <span class="vd-pin vd-pin--resort vd-pin--full"><i class="bi bi-house-heart-fill" aria-hidden="true"></i></span>
                                Fully booked
                            </li>
                            <li class="vd-map-legend__hint">
                                Tap a pin for details, or pick from the list. <strong>Map</strong> and
                                <strong>Satellite</strong> are at the bottom left; what is drawn on top of them
                                is in the panel at the top right. Occupancy is what the resort has confirmed so
                                far, not a guarantee &mdash; the resort checks again when you book.
                                @if (count($fuel))
                                    Filling stations come from OpenStreetMap and are not checked by the tourism office.
                                @endif
                            </li>
                        </ul>
                    </details>
                </div>

                {{-- RIGHT: what you have picked ------------------------------- --}}
                <aside class="vd-gis__pane vd-gis__pane--info" aria-label="Barangay information and directions">
                    <section class="vd-gis__card-panel" data-gis-brgy-panel>
                        <header class="vd-gis__panelhead">
                            <h2><i class="bi bi-info-circle" aria-hidden="true"></i>Barangay information</h2>
                            <button type="button" class="vd-gis__panelclose" data-gis-brgy-close hidden>
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                                <span class="visually-hidden">Clear the selected barangay</span>
                            </button>
                        </header>

                        {{-- Before anything is picked. Not an error state: it
                             says what to do. --}}
                        <p class="vd-gis__hint" data-gis-brgy-idle>
                            Choose a barangay on the map or in the list to see what is in it.
                        </p>

                        <div data-gis-brgy-body hidden>
                            <h3 class="vd-gis__brgyname" data-gis-brgy-name></h3>
                            <p class="vd-gis__brgywhere">Dasol, Pangasinan</p>

                            {{-- Counts, and nothing else. Land area, coastline
                                 and population are not recorded anywhere in
                                 this system, so there is no line for them. --}}
                            <dl class="vd-gis__stats">
                                <div>
                                    <dt>Resorts</dt>
                                    <dd data-gis-brgy-resorts>0</dd>
                                </div>
                                <div>
                                    <dt>Destinations</dt>
                                    <dd data-gis-brgy-spots>0</dd>
                                </div>
                            </dl>

                            {{-- Where the point came from, when it came from a
                                 landmark. More useful than the word
                                 "approximate": a visitor can navigate to a
                                 school. --}}
                            <p class="vd-gis__brgynote" data-gis-brgy-landmark hidden></p>

                            <button type="button" class="btn btn-sm btn-outline-primary w-100"
                                    data-gis-brgy-filter>
                                Show only this barangay in the list
                            </button>
                        </div>

                        {{-- The outlines are a legal dataset the office has to
                             supply. Saying so beats leaving a visitor to guess
                             why the map has labels and no shapes. --}}
                        @unless ($barangayShapes)
                            <p class="vd-gis__missing">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                Barangay outlines have not been supplied yet, so the map shows
                                name labels instead of boundaries.
                                @if (count($missingPoints))
                                    No point has been confirmed for
                                    {{ implode(', ', $missingPoints) }}.
                                @endif
                            </p>
                        @endunless

                    </section>

                    {{-- The road between any two places on the map.

                         Rendered by Blade rather than built in JavaScript, so
                         the menus list exactly what the server put on the map
                         and are there before any script runs. Somebody
                         planning a trip from home needs this; "how far from
                         where I am standing" only helps once they have already
                         set off. --}}
                    <section class="vd-gis__card-panel">
                        <header class="vd-gis__panelhead">
                            <h2><i class="bi bi-signpost-split" aria-hidden="true"></i>Directions</h2>
                        </header>

                        <form class="vd-trip vd-gis__trip" data-map-trip
                              @if ($hasPlaceSearch) data-place-search="{{ route('places.search') }}" @endif>
                            <div class="vd-trip__field">
                                <label class="form-label" for="trip-from">From</label>

                                {{-- Typed, not chosen. Anyone travelling TO Dasol starts
                                     somewhere that is not in Dasol, so a menu of local
                                     places cannot answer "how far from Alaminos". The
                                     datalist keeps the places already on this map one tap
                                     away, and anything else typed is looked up. --}}
                                <input class="form-control form-control-sm" type="text" id="trip-from"
                                       list="trip-from-places" autocomplete="off"
                                       placeholder="{{ $hasPlaceSearch ? 'A town, a landmark, or a place below' : 'Choose a place below' }}"
                                       data-map-trip-from>

                                <datalist id="trip-from-places">
                                    @foreach ($places as $group => $options)
                                        @foreach ($options as $option)
                                            <option value="{{ $option['name'] }}"></option>
                                        @endforeach
                                    @endforeach
                                </datalist>

                                <button type="button" class="btn btn-sm btn-link px-0 py-0 mt-1" data-map-trip-me>
                                    <i class="bi bi-crosshair me-1" aria-hidden="true"></i>Use where I am
                                </button>
                            </div>

                            <div class="vd-trip__field">
                                <label class="form-label" for="trip-to">To</label>
                                <select class="form-select form-select-sm" id="trip-to" data-map-trip-to>
                                    @foreach ($places as $group => $options)
                                        @if (count($options))
                                            <optgroup label="{{ $group }}">
                                                @foreach ($options as $option)
                                                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endif
                                    @endforeach
                                    <option value="me">Where you are</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-sm btn-accent">
                                <i class="bi bi-signpost-split me-1" aria-hidden="true"></i>Road distance
                            </button>
                        </form>

                        <p class="vd-gis__hint mb-0">
                            Driving distances come from the road network, not a straight line.
                            They are estimates and do not allow for closures or the state of the road.
                        </p>
                    </section>
                </aside>

                {{-- Filled in after a route is drawn, and hidden again if the
                     heights cannot be fetched. The distance and the driving time
                     above never depend on it. Below the panes rather than over
                     the map, so a long reading has room and the map's own credit
                     line stays readable. --}}
                <div class="vd-profile vd-gis__profile" data-route-profile role="status" hidden>
                    {{-- The words and the drawing are written here by the script;
                         the key below is not, so it survives. --}}
                    <div data-route-profile-body></div>

                    {{-- The key to the colours painted on the road. In the page
                         rather than built in JavaScript, so it reads the same to a
                         screen reader as to everyone else. These colours match
                         slopeStyle() in map.js. --}}
                    <p class="vd-slope-key mb-0" data-route-profile-key hidden>
                        <span><i style="background:#0F7C7A"></i> Level</span>
                        <span><i style="background:#E08A1E"></i> Climbs</span>
                        <span><i style="background:#B3321F"></i> Climbs steeply</span>
                        <span><i style="background:#5E9BD6"></i> Drops</span>
                        <span><i style="background:#2F5FA8"></i> Drops steeply</span>
                    </p>
                </div>
            </div>

            {{-- What this map can do. Each card points at the control that
                 does it, so it is a way in rather than a poster. --}}
            <ul class="vd-gis__features" aria-label="What this map can do">
                <li>
                    <span class="vd-gis__feature-no">1</span>
                    <h2>Search and filter</h2>
                    <p>Find a resort, a beach, or a barangay and the map follows you to it.</p>
                </li>
                <li>
                    <span class="vd-gis__feature-no">2</span>
                    <h2>Street map and satellite</h2>
                    <p>Switch between the street map and aerial imagery at the bottom left of the map.</p>
                </li>
                <li>
                    <span class="vd-gis__feature-no">3</span>
                    <h2>Barangays</h2>
                    <p>
                        @if ($barangayShapes)
                            Tap an outline to pick a barangay out and see what is in it.
                        @else
                            {{ $barangays->count() }} of the 18 are placed on the map. Outlines are
                            not available yet.
                        @endif
                    </p>
                </li>
                <li>
                    <span class="vd-gis__feature-no">4</span>
                    <h2>Rooms free tonight</h2>
                    {{-- "Name other nights to see those instead" was here, and
                         described the date pickers that have been removed from
                         this page. A card promising a control that is not on
                         the screen is worse than one that says less. --}}
                    <p>Pins are coloured by what each resort has confirmed for tonight.
                        Open a resort to choose other dates.</p>
                </li>
                <li>
                    <span class="vd-gis__feature-no">5</span>
                    <h2>Road distance</h2>
                    <p>Driving distance and time between any two places on this map, drawn on the road.</p>
                </li>
                <li>
                    <span class="vd-gis__feature-no">6</span>
                    <h2>Layers</h2>
                    <p>Turn resorts, spots, barangays and filling stations on and off from the panel on the map.</p>
                </li>
            </ul>

            <p class="vd-gis__credit">
                Map data from OpenStreetMap, imagery from Esri. Pins are placed by resort owners and the
                tourism office, so treat them as a guide and follow local directions on the ground.
                Road distances are estimates for driving and do not account for closures or the state of
                the road.
            </p>
        @endif
    </div>
@endsection
