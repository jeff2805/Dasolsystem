<?php

/*
|--------------------------------------------------------------------------
| Visit Dasol configuration
|--------------------------------------------------------------------------
|
| Project-wide settings that may change without code edits.
| Read values with config('visitdasol.key'), for example:
|     config('visitdasol.barangays')
|     config('visitdasol.booking.max_nights')
|
| After editing this file on a cached install, run: php artisan config:clear
|
*/

return [

    'name' => env('APP_NAME', 'Visit Dasol'),

    'tagline' => 'Explore. Discover. Experience.',

    'municipality' => 'Dasol',

    'province' => 'Pangasinan',

    'postal_code' => '2411',

    /*
    | Municipal Tourism Office contact details shown in the footer.
    | Leave a value empty to hide it. Use the office's official details only.
    */
    'contact' => [
        'office' => 'Municipal Tourism Office',
        'address' => 'Dasol, Pangasinan 2411',
        'phone' => env('MTO_CONTACT_PHONE'),
        'email' => env('MTO_CONTACT_EMAIL'),
        'facebook_url' => env('MTO_CONTACT_FACEBOOK_URL'),
    ],

    /*
    | The 18 barangays of Dasol (Philippine Statistics Authority PSGC list).
    | Used for form dropdowns, validation, filters, and report grouping.
    */
    'barangays' => [
        'Alilao',
        'Amalbalan',
        'Bobonot',
        'Eguia',
        'Gais-Guipe',
        'Hermosa',
        'Macalang',
        'Magsaysay',
        'Malacapas',
        'Malimpin',
        'Osmeña',
        'Petal',
        'Poblacion',
        'San Vicente',
        'Tambac',
        'Tambobong',
        'Uli',
        'Viga',
    ],

    /*
    |--------------------------------------------------------------------------
    | Where each barangay sits on the map
    |--------------------------------------------------------------------------
    |
    | A point per barangay, so a visitor can see the shape of the municipality
    | and not just the handful of places with a listing in it.
    |
    | PROVENANCE MATTERS HERE, so every entry says where its number came from:
    |
    |   'place'     the barangay itself is mapped in OpenStreetMap. Trustworthy.
    |   'landmark'  OpenStreetMap has no point for the barangay, so this is the
    |               elementary school that carries its name. Schools sit in the
    |               built-up part of a barangay, so it is a fair guide and
    |               nothing more. Shown to visitors as approximate.
    |   null        nothing could be confirmed. The barangay is simply left off
    |               the map rather than guessed at, because a wrong pin on a
    |               municipal site is worse than no pin.
    |
    | Five are blank on purpose: Alilao and San Vicente are not in OpenStreetMap
    | at all, and Hermosa, Magsaysay and Osmeña matched a school whose name does
    | not carry the barangay's, so there is no way to tell from here whether it
    | stands in the right place.
    |
    | THE OFFICE SHOULD CORRECT THESE. Anyone who knows Dasol can read a pin off
    | the coordinate picker on the tourist spot form and paste it in below, then
    | change 'landmark' to 'office' so the map stops calling it approximate.
    | Run "php artisan config:clear" after editing.
    */
    'barangay_points' => [
        'Alilao' => null,
        'Amalbalan' => ['lat' => 15.9635771, 'lng' => 119.8701802, 'source' => 'landmark', 'landmark' => 'Amalbalan Elementary School'],
        'Bobonot' => ['lat' => 16.0023582, 'lng' => 119.8641383, 'source' => 'landmark', 'landmark' => 'Bobonot Elementary School'],
        'Eguia' => ['lat' => 15.9079060, 'lng' => 119.8833827, 'source' => 'landmark', 'landmark' => 'Eguia Elementary School'],
        'Gais-Guipe' => ['lat' => 15.9788339, 'lng' => 119.8693781, 'source' => 'landmark', 'landmark' => 'Gais Guipe Elementary School'],
        'Hermosa' => null,
        'Macalang' => ['lat' => 15.8859326, 'lng' => 119.8951349, 'source' => 'landmark', 'landmark' => 'Macalang Elementary School'],
        /*
         * Confirmed against OpenStreetMap, 26 Oct 2026. The reverse
         * lookup at this point returns address.village = "Magsaysay"
         * and address.hamlet = "Cabicalan" — OSM itself placing the
         * school inside the barangay, which is the evidence that was
         * missing before. Run visitdasol:barangay-points to repeat it.
         */
        'Magsaysay' => ['lat' => 15.9913222, 'lng' => 119.8577853, 'source' => 'landmark', 'landmark' => 'Juan Bustamante Elementary School'],
        'Malacapas' => ['lat' => 15.9677367, 'lng' => 119.8417319, 'source' => 'landmark', 'landmark' => 'Malacapas Elementary School'],
        'Malimpin' => ['lat' => 15.9988936, 'lng' => 119.9083578, 'source' => 'place'],
        'Osmeña' => null,
        'Petal' => ['lat' => 15.9299769, 'lng' => 119.8757826, 'source' => 'landmark', 'landmark' => 'Petal Elementary School'],
        'Poblacion' => ['lat' => 15.9903076, 'lng' => 119.8796207, 'source' => 'place'],
        'San Vicente' => null,
        'Tambac' => ['lat' => 15.9176446, 'lng' => 119.9161443, 'source' => 'place'],
        'Tambobong' => ['lat' => 15.9327468, 'lng' => 119.7823202, 'source' => 'landmark', 'landmark' => 'Tambobong Elementary School'],
        'Uli' => ['lat' => 15.9598656, 'lng' => 119.8204150, 'source' => 'landmark', 'landmark' => 'Uli Elementary School'],
        'Viga' => ['lat' => 15.9198673, 'lng' => 119.9310242, 'source' => 'landmark', 'landmark' => 'Viga Elementary School'],
    ],

    /*
    | Leaflet / OpenStreetMap settings.
    | The center is the municipal center (15.9902, 119.8811).
    | The bounds are an intentionally generous, APPROXIMATE box around Dasol.
    | Resort and destination coordinates outside it are rejected.
    | We tighten these values in Phase 6 when the coordinate picker is built.
    */
    'map' => [
        'center' => ['lat' => 15.9902, 'lng' => 119.8811],
        'zoom' => 12,
        'min_zoom' => 10,
        'max_zoom' => 18,
        /*
        | mapbox_token was here. Nothing reads it now that the tiles
        | come from OpenStreetMap and Esri, neither of which needs an
        | account.
        |
        | MAPBOX_PUBLIC_TOKEN is left in .env and in config/services.php
        | on purpose: emptying somebody's environment file is not this
        | change's business, and a token sitting unused costs nothing.
        | Delete both when you are sure nothing else wants it.
        */
        'bounds' => [
            'south' => 15.80,
            'west' => 119.72,
            'north' => 16.12,
            'east' => 120.02,
        ],
        /*
        | The street map: OpenStreetMap's own tiles.
        |
        | Back from Mapbox, which needed an account and a token on every
        | tile request. OSM needs neither, and the road data underneath
        | both was always theirs.
        |
        | Their tile usage policy asks for the attribution below and no
        | heavy automated traffic. A municipal tourism map is well
        | inside that; a busy production site should move to a tile host
        | with a plan, and only this line changes when it does.
        */
        'tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',

        /*
        | Aerial imagery, offered beside the street map by the layer switch.
        | Esri's World Imagery needs no account and is free to use with the
        | attribution below. Set satellite_url to null to drop the switch.
        |
        | Whatever host is named here is added to the Content Security Policy
        | automatically (see App\Http\Middleware\SecurityHeaders), so the
        | browser will refuse tiles from anywhere this file does not name.
        */
        'satellite_url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',

        'satellite_attribution' => 'Imagery &copy; <a href="https://www.esri.com/">Esri</a>, Maxar, Earthstar Geographics',

        /*
        | How deep Esri's imagery actually goes over Dasol. Leaflet will
        | not ask for a tile past this; it stretches the last real one
        | instead, so a close zoom goes soft rather than blank.
        */
        'satellite_max_zoom' => 19,

        /*
        | Names on top of the imagery.
        |
        | Aerial photography has no writing on it. Beautiful, and useless
        | for "which road is that" — the complaint this answers. These two
        | are Esri's own reference layers: TRANSPARENT PNG overlays meant
        | to be drawn over imagery, one carrying place and locality names,
        | the other roads and their names.
        |
        | A plain OpenStreetMap tile layer CANNOT do this job. Its tiles
        | are opaque: laid over the imagery they simply hide it, which is
        | the street map with extra steps. Only a layer published as
        | transparent reference works, which is why these are Esri's.
        |
        | Same host, same licence and the same attribution as the imagery
        | above, so nothing new is being agreed to. Set either to null to
        | drop that overlay; the imagery keeps working without them.
        |
        | These URLs are added to the Content Security Policy from here
        | too, so a provider changed in this file cannot leave the policy
        | behind and have the browser silently refuse the tiles.
        */
        'satellite_labels_url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
        'satellite_roads_url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}',

        /*
        | Road distance and driving time from a visitor's own position to a
        | resort, drawn as a route line on the map.
        |
        | This is the public demo server of the OSRM project. It is free and
        | needs no key, but its operators ask that it not be used for heavy or
        | commercial traffic, and it carries no uptime promise. If it cannot be
        | reached the map falls back to straight-line distance, which is always
        | calculated in the browser and never leaves it.
        |
        | Set routing_url to null to turn routing off and keep straight-line
        | distances only. Nothing but the two sets of coordinates is ever sent.
        */
        'routing_url' => 'https://router.project-osrm.org/route/v1/driving/',

        /*
        | Road distance from the visitor to EVERY pin at once.
        |
        | OSRM's table service answers one request with the distance and
        | driving time to all of them, which is why the list can show road
        | figures without one request per resort.
        |
        | The same public demo server, with the same caveat, and it has been
        | seen to answer 403 for a while and then work again. The map treats a
        | failure as normal: the straight-line figures it already showed simply
        | stay, clearly labelled as such.
        */
        'routing_table_url' => 'https://router.project-osrm.org/table/v1/driving/',

        // Most pins to ask about in one request, so a busy map cannot build a
        // URL the service refuses.
        'routing_table_limit' => 40,

        /*
        | Turning a typed place name into a point, for the map's "From" box.
        |
        | A visitor travelling to Dasol starts somewhere that is not in Dasol,
        | so the menu of local places cannot answer "how far is it from
        | Alaminos". This is OpenStreetMap's own search.
        |
        | It is called from the SERVER, never the browser: the service asks
        | every caller to identify itself in a User-Agent, which a browser
        | cannot set, and answers cached here are shared between every visitor.
        | See App\Services\PlaceFinder.
        |
        | Set to null to turn the box back into a menu of local places only.
        */
        'search_url' => 'https://nominatim.openstreetmap.org/search',

        /*
        | Height above sea level along a route, so the map can say whether a
        | road climbs or drops.
        |
        | The routing service answers distance and driving time, but its
        | geometry carries only longitude and latitude — the height is not in
        | it. That matters here: most visitors reach Dasol's barangays by
        | tricycle or motorcycle, and a steep climb matters to both in a way it
        | does not to a car.
        |
        | Open-Meteo's elevation service. Free, no key, and generous enough for
        | a municipal site. Called from the SERVER and cached per point, so a
        | road travelled twice is only ever measured once; see
        | App\Services\Elevation.
        |
        | Set to null to drop the climb-and-drop reading. Distance and driving
        | time are unaffected.
        */
        'elevation_url' => 'https://api.open-meteo.com/v1/elevation',

        /*
        | Where to fill up, for visitors driving or riding in.
        |
        | Read from OpenStreetMap's Overpass service, from the SERVER and
        | cached here, for the same reasons as search_url above: Overpass
        | asks callers to identify themselves, answers are shared between
        | every visitor, and the page then opens no hole in the Content
        | Security Policy.
        |
        | NOT typed in by hand. Nobody here has surveyed Dasol's petrol
        | stations, and a wrong pin on a municipal site is worse than no
        | pin — the same rule the barangay points above are held to.
        |
        | The main Overpass endpoint is free and frequently at capacity:
        | it answers 502 when its queue is full. The mirrors below are
        | tried in order, so one busy server does not empty the layer.
        | A failure is only cached for minutes, never for the week a
        | successful answer is kept.
        |
        | Set fuel_url to an empty list to drop the layer entirely.
        */
        'fuel_url' => [
            'https://overpass-api.de/api/interpreter',
            'https://overpass.kumi.systems/api/interpreter',
        ],

        /*
        | WHICH STATIONS COUNT AS "IN DASOL".
        |
        | OpenStreetMap has no boundary for Dasol — the municipality is a
        | single point in its data, not a shape — so there is no outline to
        | test a station against. This box is the next best thing, and it
        | is NOT the generous 'bounds' box above: that one deliberately
        | overshoots into Infanta, Burgos and Mabini, and using it here
        | would put their filling stations on Dasol's map.
        |
        | Drawn around the barangay points listed earlier in this file,
        | with a margin for the five that are not mapped at all. It is a
        | rectangle and Dasol is not, so it may still clip a station near
        | the edge or admit a neighbour's.
        |
        | THE OFFICE SHOULD CHECK WHAT APPEARS. Tighten these four numbers
        | if a station shows up that is not in Dasol, then run
        | "php artisan cache:clear".
        */
        'fuel_bounds' => [
            'south' => 15.87,
            'west' => 119.76,
            'north' => 16.02,
            'east' => 119.95,
        ],

        // A filling station does not move. Long, so Overpass is rarely asked.
        'fuel_cache_hours' => 168,
    ],

    /*
    | Booking engine limits (used from Phase 11).
    */
    'booking' => [
        'reference_prefix' => 'VD',
        'max_nights' => 30,
        'max_advance_days' => 365,
        'max_pending_per_tourist' => 3,
        // Tourists may cancel a confirmed booking until this many days before check-in.
        'tourist_cancel_cutoff_days' => 0,

        /*
        | How long after a guest's checkout HOUR a confirmed stay is closed
        | on its own, in hours.
        |
        | Zero means as soon as the hour has passed, which is what a stay
        | being over actually means. Raise it to give owners a window to
        | handle a no-show or an overstay differently before the portal
        | files the stay as finished; the housekeeping job runs hourly, so
        | anything set here is honoured to the hour.
        */
        'auto_complete_grace_hours' => 0,
    ],

    /*
    | What a resort asks for up front, when it has not said otherwise.
    |
    | Every resort may set its own terms on its payment policy page; these
    | are what a listing uses until an owner does. They are deliberately
    | middle-of-the-road: enough of a deposit to mean something, long
    | enough to pay it, and cash on arrival still allowed, because that is
    | how most of Dasol already works.
    |
    | requirement        none | deposit | full
    | deposit_percent    1-100, used only when requirement is deposit
    | deadline_hours     how long the guest has to pay
    | deadline_reference confirmation | booking | check_in
    | last_minute_rule   full | deposit | on_site, for an imminent arrival
    | last_minute_hours  how close to arrival counts as last minute
    | allow_on_site      whether paying at the gate is offered at all
    */
    'payment_policy' => [
        'requirement' => 'deposit',
        'deposit_percent' => 30,
        'deadline_hours' => 24,
        'deadline_reference' => 'confirmation',
        'last_minute_rule' => 'full',
        'last_minute_hours' => 48,
        'allow_on_site' => true,
    ],

    /*
    | Upload rules (sizes in kilobytes, dimensions in pixels).
    | SVG is deliberately not allowed because it can contain scripts.
    */
    'uploads' => [
        'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        'image_max_kb' => 3072,
        'image_min_width' => 400,
        'image_min_height' => 300,
        'image_max_width' => 6000,
        'image_max_height' => 6000,
        'document_mimes' => ['jpg', 'jpeg', 'png', 'pdf'],
        'document_max_kb' => 5120,
        'gallery_limits' => [
            'resort' => 10,
            'room' => 5,
            'destination' => 10,
        ],
        // Folder names inside the public and private disks.
        'folders' => [
            'resorts' => 'resorts',
            'rooms' => 'rooms',
            'destinations' => 'destinations',
            'announcements' => 'announcements',
            'owner_documents' => 'owner-documents',
        ],
    ],

    /*
    | Email verification by one-time code, shown after registration and on the
    | first sign-in of an account that has never confirmed its address.
    |
    | length      digits in the code. Six is the familiar length and, with the
    |             attempt limit below, leaves no room for guessing.
    | ttl_minutes how long a code stays valid.
    | max_attempts wrong guesses allowed before the code is thrown away and the
    |             person must ask for a new one.
    | resend_cooldown_seconds wait between "send another code" requests, so the
    |             form cannot be used to flood someone's inbox.
    */
    'otp' => [
        'length' => 6,
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
    ],

    'per_page' => 12,

    /*
    | The first Municipal Tourism Office (super admin) account.
    | Created by database/seeders/SuperAdminSeeder.php. There is no public
    | registration for this role. The password must be 12+ characters with
    | upper- and lowercase letters and a number.
    */
    'mto_admin' => [
        'first_name' => env('MTO_ADMIN_FIRST_NAME', 'Tourism'),
        'last_name' => env('MTO_ADMIN_LAST_NAME', 'Office'),
        'email' => env('MTO_ADMIN_EMAIL'),
        'phone' => env('MTO_ADMIN_PHONE'),
        'password' => env('MTO_ADMIN_PASSWORD'),
    ],

    /*
    | Demo data (fictional resorts, owners, tourists, destinations).
    | Never seeded in production. Every demo account uses the email domain
    | below and the password from DEMO_ACCOUNT_PASSWORD.
    */
    'demo' => [
        'enabled' => (bool) env('SEED_DEMO_DATA', false),
        'password' => env('DEMO_ACCOUNT_PASSWORD'),
        'email_domain' => 'demo.visitdasol.test',

        /*
        | Whether fictional listings appear on the public site.
        |
        | Off by default: once real resorts have registered, a visitor reading
        | "Places to stay" should be reading real places. The demo records are
        | not deleted, so nothing is lost and nothing has to be seeded again —
        | set DEMO_LISTINGS_PUBLIC=true to bring them back for a walkthrough
        | on an otherwise empty system.
        |
        | This hides them from the PUBLIC pages only. The tourism office still
        | sees every listing in its own screens, which is where a demo record
        | would need to be found in order to be removed.
        */
        'listings_public' => (bool) env('DEMO_LISTINGS_PUBLIC', false),
    ],

];
