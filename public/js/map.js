/*
 * The Visit Dasol map.
 *
 *   <div class="vd-map" data-visit-map id="dasol-map"
 *        data-points='[{"id":"resort-1","type":"resort","name":"...","lat":..,"lng":..,
 *                       "subtitle":"...","image":"...","url":"...","status":"active"}]'
 *        data-center='{"lat":..,"lng":..}' data-zoom="12" ...></div>
 *
 * Points always come from the database through the page, never from code
 * written here. Popups are built from text nodes, so a resort name that
 * contains markup is shown as text and can never run as code.
 *
 * Any element with data-map-focus="<point id>" pans the map to that point
 * and opens its popup, which is how the list beside the map works.
 *
 * With data-locate the map also offers:
 *   - a street/satellite switch (data-satellite-url),
 *   - a "My location" button using the browser's own geolocation,
 *   - straight-line distance to every point, worked out in the browser,
 *   - road distance and driving time for one point at a time, from the
 *     routing service at data-routing-url.
 *
 * An element with data-map-distance="<point id>" is filled with the distance
 * once a location is known, which is how the list shows its figures.
 *
 * The map's own controls — data-map-locate, data-map-readout, data-map-trip and
 * data-route-profile — are looked for inside the nearest [data-map-scope]
 * ancestor, or beside the map element when there is none. Put that attribute on
 * a wrapper when the controls need to sit somewhere other than next to the map.
 */
(function () {
  'use strict';

  var ICONS = {
    resort: 'house-heart-fill',
    destination: 'signpost-2-fill',
  };

  /* Geometry and formatting, shared with location-map.js. See geo.js. */
  var geo = (window.VisitDasol || {}).geo || {};
  var straightLineKm = geo.straightLineKm;
  var formatKm = geo.formatKm;
  var formatMinutes = geo.formatMinutes;

  /* Markers and popups --------------------------------------------------- */

  /*
   * A resort's pin is coloured by how full it is. Only ever from figures
   * the server worked out: the browser is told the status, it does not
   * decide it, so the map and the booking form can never disagree.
   *
   * "unknown" keeps the ordinary pin rather than claiming a resort is
   * free. A listing with nothing published has not told us it is empty.
   */
  var OCCUPANCY_CLASS = {
    available: 'vd-pin--available',
    busy: 'vd-pin--busy',
    full: 'vd-pin--full',
    unknown: 'vd-pin--unknown',
  };

  function markerIcon(point) {
    var classes = ['vd-pin', 'vd-pin--' + (point.type === 'destination' ? 'destination' : 'resort')];

    if (point.status && point.status !== 'active') {
      classes.push('vd-pin--muted');
    }

    if (point.type === 'resort' && point.occupancy) {
      classes.push(OCCUPANCY_CLASS[point.occupancy.status] || OCCUPANCY_CLASS.unknown);
    }

    return L.divIcon({
      className: 'vd-pin-wrap',
      html: '<span class="' + classes.join(' ') + '"><i class="bi bi-' +
        (ICONS[point.type] || 'geo-alt-fill') + '"></i></span>',
      iconSize: [30, 30],
      iconAnchor: [15, 30],
      popupAnchor: [0, -28],
    });
  }

  /*
   * For the one place a string has to become markup: a Leaflet divIcon takes
   * HTML, not a text node, so a barangay name is escaped before it goes in.
   * These names come from config and not from a form, but the next person to
   * edit this file should not have to know that.
   */
  function escapeHtml(value) {
    var holder = document.createElement('span');
    holder.textContent = String(value);

    return holder.innerHTML;
  }

  function plural(count, word) {
    return count + ' ' + word + (count === 1 ? '' : 's');
  }

  var OCCUPANCY_WORD = {
    available: 'Rooms free',
    busy: 'Filling up',
    full: 'Fully booked',
    unknown: 'Availability not published',
  };

  /*
   * How full one resort is, as nodes. Aggregate counts only — who is
   * staying is never sent to the browser, so it cannot be shown here.
   *
   * projected says whether these are tonight's figures or a forecast for
   * the nights the visitor asked about. The two are different claims and
   * the popup makes which one plain.
   */
  function occupancyBlock(occupancy, projected) {
    var wrap = document.createElement('div');
    wrap.className = 'vd-occupancy vd-occupancy--' + (occupancy.status || 'unknown');

    var status = document.createElement('span');
    status.className = 'vd-occupancy__status';
    status.textContent = OCCUPANCY_WORD[occupancy.status] || OCCUPANCY_WORD.unknown;
    wrap.appendChild(status);

    if (occupancy.status === 'unknown' || !occupancy.capacity) {
      var none = document.createElement('span');
      none.className = 'vd-occupancy__line';
      none.textContent = 'This resort has not published its rooms yet.';
      wrap.appendChild(none);

      return wrap;
    }

    var guests = document.createElement('span');
    guests.className = 'vd-occupancy__line';
    guests.textContent = (projected ? 'Booked for those nights: ' : 'Staying tonight: ') +
      occupancy.guests + ' / ' + plural(occupancy.capacity, 'guest');
    wrap.appendChild(guests);

    /*
     * A bar as well as the numbers, but never the bar alone: it carries
     * the same figure in a form that can be read at a glance, and its
     * ARIA values carry it for a reader that cannot see it at all.
     */
    var meter = document.createElement('div');
    meter.className = 'vd-occupancy__meter';
    meter.setAttribute('role', 'progressbar');
    meter.setAttribute('aria-label', 'Guest occupancy');
    meter.setAttribute('aria-valuenow', String(occupancy.percent));
    meter.setAttribute('aria-valuemin', '0');
    meter.setAttribute('aria-valuemax', '100');

    var fill = document.createElement('span');
    fill.style.width = Math.max(0, Math.min(100, occupancy.percent)) + '%';
    meter.appendChild(fill);
    wrap.appendChild(meter);

    var detail = document.createElement('span');
    detail.className = 'vd-occupancy__line';
    detail.textContent = occupancy.percent + '% full · ' +
      plural(occupancy.remaining, 'guest space') + ' left · ' +
      plural(occupancy.rooms_free, 'room') + ' free';
    wrap.appendChild(detail);

    return wrap;
  }

  /*
   * A filling station. Built as nodes, so a name edited by a stranger on
   * OpenStreetMap is shown as text and can never run as markup.
   *
   * The source is named out loud. These pins are not the tourism
   * office's: they are volunteer-mapped, they may be out of date, and a
   * visitor running low on fuel deserves to know how much to trust one
   * before driving to it.
   */
  function fuelPopup(station) {
    return function () {
      var wrap = document.createElement('div');
      wrap.className = 'vd-map-popup';

      var name = document.createElement('strong');
      name.textContent = station.name;
      wrap.appendChild(name);

      if (station.brand) {
        var brand = document.createElement('span');
        brand.className = 'vd-map-popup__meta';
        brand.textContent = station.brand;
        wrap.appendChild(brand);
      }

      var source = document.createElement('span');
      source.className = 'vd-map-popup__meta';
      source.textContent = 'Mapped by OpenStreetMap contributors, not checked '
        + 'by the tourism office. Opening hours and fuel are not guaranteed.';
      wrap.appendChild(source);

      return wrap;
    };
  }

  /* Built as nodes, so nothing here can run as markup. */
  function barangayPopup(barangay) {
    return function () {
      var wrap = document.createElement('div');
      wrap.className = 'vd-map-popup';

      var name = document.createElement('strong');
      name.textContent = 'Barangay ' + barangay.name;
      wrap.appendChild(name);

      var note = document.createElement('span');
      note.className = 'vd-map-popup__meta';

      if (barangay.landmark) {
        // Naming the landmark beats the word "approximate" on its own: a
        // visitor cannot navigate to a vague area, but they can navigate to
        // a school, and they can see for themselves how rough the pin is.
        note.textContent = 'Marked at ' + barangay.landmark +
          '. That is a landmark in this barangay, not its exact centre.';
      } else if (barangay.approximate) {
        note.textContent = 'Approximate. This marks the area, not an exact spot, '
          + 'and the tourism office has not confirmed it.';
      } else {
        note.textContent = 'One of the 18 barangays of Dasol.';
      }

      wrap.appendChild(note);

      return wrap;
    };
  }

  /*
   * What the browser actually knows, written out. Accuracy matters more than
   * it looks: indoors a reading can be half a kilometre out, and a visitor
   * who can see that will not trust a distance further than it deserves.
   */
  /*
   * corrected: the visitor dragged the pin here themselves. The popup
   * then says so and drops the accuracy line — a margin of error
   * belongs to a measurement, and this is not one.
   */
  function herePopup(coords, corrected) {
    var wrap = document.createElement('div');
    wrap.className = 'vd-map-popup';

    var title = document.createElement('strong');
    title.textContent = corrected ? 'Where you said you are' : 'You are here';
    wrap.appendChild(title);

    var where = document.createElement('span');
    where.className = 'vd-map-popup__meta';
    where.textContent = coords.latitude.toFixed(5) + ', ' + coords.longitude.toFixed(5);
    wrap.appendChild(where);

    var sure = document.createElement('span');
    sure.className = 'vd-map-popup__meta';

    if (corrected) {
      sure.textContent = 'You placed this yourself. Drag it again to move it.';
    } else {
      var quality = (window.VisitDasol || {}).geo.describeAccuracy(coords.accuracy);

      sure.textContent = 'Your browser reports this as '
        + quality.text + '. Drag the pin if it is wrong.';
    }

    wrap.appendChild(sure);

    return wrap;
  }

  function hereIcon() {
    return L.divIcon({
      className: 'vd-pin-wrap',
      html: '<span class="vd-pin vd-pin--here"><i class="bi bi-person-fill"></i></span>',
      iconSize: [30, 30],
      iconAnchor: [15, 30],
      popupAnchor: [0, -28],
    });
  }

  function setUp(element) {
    if (typeof L === 'undefined') {
      return;
    }

    /*
     * Where to look for the map's own controls: the "My location" button, the
     * readout, the trip planner, and the climb profile.
     *
     * These used to have to be siblings of the map element, which quietly
     * dictated the page layout — the two-pane map could not put the map in one
     * pane and its controls in another without the buttons going dead. The
     * nearest [data-map-scope] ancestor is searched instead, and a page that
     * declares none keeps the old behaviour exactly.
     */
    var scope = element.closest('[data-map-scope]') || element.parentNode;

    var points = JSON.parse(element.dataset.points || '[]');
    var center = JSON.parse(element.dataset.center || '{}');

    // The municipal box, used only to warn somebody their browser has
    // put them outside Dasol. Nothing is refused on the strength of it.
    var bounds = JSON.parse(element.dataset.bounds || 'null');

    var maxZoom = parseInt(element.dataset.maxZoom, 10) || 18;
    var canLocate = element.hasAttribute('data-locate');
    var routingUrl = element.dataset.routingUrl || null;
    var tableUrl = element.dataset.routingTableUrl || null;
    var tableLimit = parseInt(element.dataset.routingTableLimit, 10) || 40;

    // Road distance and driving time per point id, once the routing service
    // has answered. Empty until then, and it may stay empty.
    var byRoad = {};

    var map = L.map(element, {
      minZoom: parseInt(element.dataset.minZoom, 10) || 8,
      maxZoom: maxZoom,
    }).setView([center.lat, center.lng], parseInt(element.dataset.zoom, 10) || 12);

    /* Base layers ------------------------------------------------------- */

    /*
     * A ring of tiles beyond the edge of the screen, so a drag shows map
     * rather than the grey behind it. Leaflet keeps two by default; four
     * covers an ordinary flick on a phone.
     */
    var KEEP_BUFFER = 4;

    var streets = L.tileLayer(element.dataset.tileUrl, {
      maxZoom: maxZoom,
      keepBuffer: KEEP_BUFFER,
      attribution: element.dataset.attribution,

      /*
       * NO detectRetina HERE, deliberately — see the imagery below, which
       * does use it. Retina mode fetches the tiles of the next zoom in
       * and shrinks them. On a photograph that is pure detail; on a drawn
       * street map it also halves the size of every road name and
       * barangay label, which is the opposite of clearer.
       */
    }).addTo(map);

    var satellite = null;
    var satelliteMax = maxZoom;

    /*
     * NAMES ON THE IMAGERY.
     *
     * Aerial photography has no writing on it, so on satellite the map
     * could show a coastline beautifully and not tell you which road
     * you were looking at. These are transparent reference tiles drawn
     * over the imagery: one carries place and locality names, the other
     * roads and their names.
     *
     * They go in a pane of their own at z-index 350 — above the tiles
     * (200) and below the overlays and markers (400 and up) — so names
     * sit on the photograph but never on top of a resort pin somebody
     * is trying to tap.
     *
     * A plain street-tile layer cannot stand in for these. Its tiles
     * are opaque and would simply hide the imagery.
     */
    var labelLayers = [];

    if (element.dataset.satelliteUrl) {
      // Imagery usually reaches a closer zoom than the street map, so the
      // map's own ceiling is raised to match while it is showing.
      satelliteMax = parseInt(element.dataset.satelliteMaxZoom, 10) || maxZoom;

      /*
       * Retina mode shifts two numbers, and both have to be shifted back
       * or the imagery breaks at the closest zoom. From Leaflet's own
       * source (public/vendor/leaflet/leaflet.js):
       *
       *   - initialize() does zoomOffset++ and maxZoom--, so the layer
       *     asks for ONE ZOOM DEEPER than the map is showing. Against
       *     Esri's last published level that is a request for a tile
       *     nobody ever made, and the answer is a grey "no data" square.
       *     Hence a maxNativeZoom one lower: the deepest tile asked for
       *     is then exactly the deepest tile that exists.
       *
       *   - _setView() blanks the layer outright when the map's zoom is
       *     past the layer's maxZoom, and it tests that BEFORE clamping
       *     to maxNativeZoom. With the decrement above, a layer capped
       *     at the map's own ceiling disappears the moment somebody
       *     zooms all the way in. Hence a ceiling one higher, which the
       *     decrement brings back to the real one. The map's own
       *     setMaxZoom still decides how far a visitor can go.
       *
       * On an ordinary display neither shift happens, so neither
       * correction is applied and the layer behaves exactly as before.
       */
      var retinaTiles = L.Browser.retina;

      satellite = L.tileLayer(element.dataset.satelliteUrl, {
        maxZoom: retinaTiles ? satelliteMax + 1 : satelliteMax,
        keepBuffer: KEEP_BUFFER,
        attribution: element.dataset.satelliteAttribution,

        /*
         * SHARPNESS ON A HIGH-DENSITY SCREEN.
         *
         * Most laptops and every phone draw two or more device pixels
         * for each CSS pixel. Without this the browser is handed a
         * 256-pixel photograph and asked to paint it across 512, and
         * the coastline goes soft — which is what it looked like.
         *
         * Retina mode asks for the next zoom level in and draws it at
         * half size, so the imagery arrives at the density the screen
         * actually has. There is no lettering on a photograph to shrink,
         * which is why it suits the imagery and not the street map.
         *
         * It costs four tiles where there was one, and only on screens
         * that can show the difference: Leaflet checks the device and
         * leaves an ordinary display alone.
         */
        detectRetina: true,

        /*
         * The deepest tile to ask for, counted in the layer's own zoom
         * rather than the map's — which is why retina takes one off it,
         * as the note above this layer explains. Past it the last real
         * tile is stretched, so a close zoom goes soft instead of blank.
         */
        maxNativeZoom: retinaTiles ? satelliteMax - 1 : satelliteMax,
      });

      map.createPane('vd-labels');
      map.getPane('vd-labels').style.zIndex = 350;

      // Nothing to click on a label tile, and leaving it clickable
      // would swallow taps meant for the imagery underneath.
      map.getPane('vd-labels').style.pointerEvents = 'none';

      [
        element.dataset.satelliteRoadsUrl,
        element.dataset.satelliteLabelsUrl,
      ].forEach(function (url) {
        if (!url) {
          return;
        }

        labelLayers.push(L.tileLayer(url, {
          pane: 'vd-labels',
          maxZoom: satelliteMax,
          keepBuffer: KEEP_BUFFER,

          // These are lettering, so they are left at ordinary density for
          // the same reason the street map is: see the note on it above.

          /*
           * The reference layers are published to the same depth as the
           * imagery. Past it Leaflet stretches the last real tile rather
           * than asking for one that does not exist, so zooming in hard
           * blurs the lettering instead of losing it.
           */
          maxNativeZoom: satelliteMax,

          // The imagery's credit already names Esri and is on screen
          // whenever these are, so repeating it three times would only
          // crowd the corner.
          attribution: '',
        }));
      });
    }

    /*
     * HOW FAR IS THAT.
     *
     * The imagery gives no sense of distance on its own — a bay could be
     * two hundred metres across or two kilometres, and nothing on the
     * photograph says which. A scale bar answers that without a click,
     * and it redraws itself as the map is zoomed.
     *
     * Metric only. The imperial half would be a second number nobody
     * here reads, in a corner that is already carrying the credits.
     */
    L.control.scale({
      position: 'bottomright',
      metric: true,
      imperial: false,
      maxWidth: 140,
    }).addTo(map);

    /*
     * Choosing between the street map and the imagery.
     *
     * Leaflet's own layers control can do this, and used to: two radio
     * buttons in a list with the overlay checkboxes under them. It works,
     * but the choice a visitor most often wants to make was the least
     * visible thing on the map — a small radio among five other rows,
     * in a panel that covered a corner of a phone screen.
     *
     * Two labelled buttons instead, the way every mapping application
     * does it, with the overlays left to the layers control.
     */
    var BASEMAP_KEY = 'vd.map.basemap';

    function rememberBasemap(name) {
      try {
        window.localStorage.setItem(BASEMAP_KEY, name);
      } catch (error) {
        // Private browsing, or storage switched off. The map works the
        // same; it just starts on the street map every time.
      }
    }

    function rememberedBasemap() {
      try {
        return window.localStorage.getItem(BASEMAP_KEY);
      } catch (error) {
        return null;
      }
    }

    /*
     * THREE CHOICES, NOT TWO.
     *
     *   streets    the street map, which carries its own lettering
     *   satellite  the photograph on its own, nothing written on it
     *   hybrid     the photograph with road and place names over it
     *
     * Satellite and hybrid share one imagery layer and differ only in
     * whether the transparent reference tiles are drawn. Before this
     * there was no way to see the imagery clean: "Satellite" always
     * carried the names, so a visitor who wanted to look at the
     * coastline itself could not.
     *
     * Nothing here moves the map. Leaflet keeps the centre and zoom
     * across a layer swap, which is what makes switching feel free.
     */
    function showBasemap(name) {
      if (!satellite) {
        return;
      }

      var onImagery = name === 'satellite' || name === 'hybrid';

      // Names ride on the photograph only, and only in hybrid. Over the
      // street map they would print every name twice, slightly offset;
      // over plain satellite they are the thing being switched off.
      var wantsLabels = name === 'hybrid';

      if (onImagery && !map.hasLayer(satellite)) {
        map.removeLayer(streets);
        satellite.addTo(map);
      } else if (!onImagery && !map.hasLayer(streets)) {
        map.removeLayer(satellite);
        streets.addTo(map);
      }

      labelLayers.forEach(function (layer) {
        if (wantsLabels && !map.hasLayer(layer)) {
          layer.addTo(map);
        } else if (!wantsLabels && map.hasLayer(layer)) {
          map.removeLayer(layer);
        }
      });

      var wantsSatellite = onImagery;

      /*
       * The barangay chips and outline labels need a heavier halo over
       * a photograph than over the street map's pale ground, and the
       * stylesheet switches on this.
       */
      element.classList.toggle('is-satellite', wantsSatellite);

      // The imagery reaches a closer zoom than the street map, so the
      // ceiling follows whichever is showing. Zoom out first if the map
      // is already closer than the new ceiling allows.
      map.setMaxZoom(wantsSatellite ? satelliteMax : maxZoom);

      element.querySelectorAll('[data-basemap]').forEach(function (button) {
        var on = button.dataset.basemap === name;

        button.classList.toggle('is-on', on);
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }

    if (satellite) {
      var basemapControl = L.control({ position: 'bottomleft' });

      basemapControl.onAdd = function () {
        var bar = L.DomUtil.create('div', 'vd-basemap');
        bar.setAttribute('role', 'group');
        bar.setAttribute('aria-label', 'Base map');

        [
          { key: 'streets', label: 'Map', icon: 'map' },
          { key: 'satellite', label: 'Satellite', icon: 'globe-americas' },
          { key: 'hybrid', label: 'Hybrid', icon: 'layers' },
        ].forEach(function (choice) {
          var button = L.DomUtil.create('button', 'vd-basemap__option', bar);

          button.type = 'button';
          button.dataset.basemap = choice.key;
          button.setAttribute('aria-pressed', 'false');
          button.innerHTML = '<i class="bi bi-' + choice.icon + '" aria-hidden="true"></i>';

          // The label as a text node, beside the decorative icon.
          button.appendChild(document.createTextNode(' ' + choice.label));

          L.DomEvent.on(button, 'click', function (event) {
            L.DomEvent.stop(event);
            showBasemap(choice.key);
            rememberBasemap(choice.key);
          });
        });

        // Otherwise a tap on the control pans the map underneath it.
        L.DomEvent.disableClickPropagation(bar);
        L.DomEvent.disableScrollPropagation(bar);

        return bar;
      };

      basemapControl.addTo(map);

      /*
       * Whatever they chose last time. Leaving the street map as the
       * default for anything unrecognised also covers the older stored
       * value from when there were only two choices.
       */
      var remembered = rememberedBasemap();

      showBasemap(
        remembered === 'satellite' || remembered === 'hybrid'
          ? remembered
          : 'streets'
      );
    }

    /* Points ------------------------------------------------------------ */

    var layers = { resort: L.layerGroup().addTo(map), destination: L.layerGroup().addTo(map) };
    var markers = {};
    var bounds = [];
    var here = null;        // the visitor's position, once they ask for it
    var hereMarker = null;
    var hereCircle = null;  // the ring showing how sure the browser is
    var routeLine = null;

    // The "Clear route" control, built once a route is actually drawn
    // and hidden again when it is taken away.
    var clearRouteButton = null;

    /*
     * Following the visitor as they move.
     *
     * Null until they ask for it. A watch left running costs battery
     * and keeps the GPS warm, so it is started deliberately and
     * stopped the moment the route is cleared or following is
     * switched off.
     */
    var followWatch = null;
    var followButton = null;

    /*
     * Built fresh every time a popup opens, so a route button can appear once
     * a location is known without every popup having to be rebuilt.
     */
    function popupFor(point) {
      return function () {
        var wrap = document.createElement('div');
        wrap.className = 'vd-map-popup';

        if (point.image) {
          var image = document.createElement('img');
          image.src = point.image;
          image.alt = '';
          image.loading = 'lazy';
          wrap.appendChild(image);
        }

        var name = document.createElement('strong');
        name.textContent = point.name;
        wrap.appendChild(name);

        if (point.subtitle) {
          var subtitle = document.createElement('span');
          subtitle.className = 'vd-map-popup__meta';
          subtitle.textContent = point.subtitle;
          wrap.appendChild(subtitle);
        }

        if (here) {
          var away = document.createElement('span');
          away.className = 'vd-map-popup__meta';
          // The road figure once it is known, the straight line until then.
          away.textContent = byRoad[point.id]
            ? formatKm(byRoad[point.id].km) + ' by road, about ' + formatMinutes(byRoad[point.id].seconds) + ' driving'
            : formatKm(straightLineKm(here, point)) + ' away in a straight line';
          wrap.appendChild(away);
        }

        if (point.type === 'resort' && point.occupancy) {
          wrap.appendChild(occupancyBlock(point.occupancy, projected));
        }

        if (point.url) {
          var link = document.createElement('a');
          link.className = 'btn btn-sm btn-outline-primary';
          link.href = point.url;
          link.textContent = 'View resort';
          wrap.appendChild(link);
        }

        /*
         * Booking happens on the resort's own page, where the dates are
         * chosen and availability is checked for real. Offered only when
         * there is something to book: a "Book now" button on a resort
         * that is full is a promise the next screen has to break.
         */
        if (point.book_url && point.occupancy &&
            point.occupancy.status !== 'full' &&
            point.occupancy.status !== 'unknown') {
          var book = document.createElement('a');
          book.className = 'btn btn-sm btn-accent';
          book.href = point.book_url + bookingDates();
          book.textContent = 'Book now';
          wrap.appendChild(book);
        }

        if (here && routingUrl) {
          var route = document.createElement('button');
          route.type = 'button';
          route.className = 'btn btn-sm btn-outline-primary';
          route.textContent = 'Route from me';
          route.addEventListener('click', function () {
            drawRoute(point, route);
          });
          wrap.appendChild(route);
        }

        return wrap;
      };
    }

    points.forEach(function (point) {
      var marker = L.marker([point.lat, point.lng], { icon: markerIcon(point), title: point.name })
        .bindPopup(popupFor(point));

      marker.addTo(layers[point.type] || layers.resort);
      markers[point.id] = marker;
      bounds.push([point.lat, point.lng]);
    });

    if (bounds.length > 1) {
      map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 });
    } else if (bounds.length === 1) {
      map.setView(bounds[0], 14);
    }

    /* Occupancy for chosen nights ---------------------------------------- */

    /*
     * The server has already drawn tonight's figures into the points, so
     * everything below is about replacing them when the visitor names
     * different nights. Without the endpoint, the map keeps what it was
     * given and the date form submits as an ordinary GET.
     */
    var occupancyUrl = element.dataset.occupancyUrl || null;
    var dateForm = scope.querySelector('[data-map-dates]');
    var checkInField = scope.querySelector('[data-map-check-in]');
    var checkOutField = scope.querySelector('[data-map-check-out]');
    var datesReadout = scope.querySelector('[data-map-dates-readout]');
    var datesClear = scope.querySelector('[data-map-dates-clear]');
    var datesButton = scope.querySelector('[data-map-dates-go]');

    // Whether what is on screen is a forecast or tonight.
    var projected = false;

    // What the popups' "Book now" should carry through to the resort page,
    // so a visitor who picked dates here does not pick them again there.
    function bookingDates() {
      if (!projected || !checkInField || !checkOutField) {
        return '';
      }

      return '?check_in=' + encodeURIComponent(checkInField.value) +
        '&check_out=' + encodeURIComponent(checkOutField.value);
    }

    /*
     * Repaint one resort: its pin colour, and its popup if it happens to
     * be open. Leaflet will not re-run a popup function while it is on
     * screen, so an open one is replaced by hand.
     */
    function repaint(point) {
      /*
       * The badge on the list card as well as the pin. The two say the
       * same thing about the same resort, and a card still reading
       * "Rooms free" beside a red pin is worse than no badge at all.
       */
      var badge = document.querySelector('[data-gis-status="' + point.id + '"]');

      if (badge) {
        var status = (point.occupancy && point.occupancy.status) || 'unknown';

        badge.className = 'vd-gis__status vd-gis__status--' + status;
        badge.textContent = OCCUPANCY_WORD[status] || OCCUPANCY_WORD.unknown;
      }

      var marker = markers[point.id];

      if (!marker) {
        return;
      }

      marker.setIcon(markerIcon(point));

      if (marker.isPopupOpen && marker.isPopupOpen()) {
        marker.setPopupContent(popupFor(point)());
      }
    }

    function sayDates(text, failed) {
      if (!datesReadout) {
        return;
      }

      datesReadout.textContent = text;
      datesReadout.classList.toggle('vd-mapdates__readout--error', !!failed);
    }

    function loadOccupancy(checkIn, checkOut) {
      if (!occupancyUrl) {
        return;
      }

      var asking = checkIn && checkOut;
      var url = occupancyUrl + (asking
        ? '?check_in=' + encodeURIComponent(checkIn) + '&check_out=' + encodeURIComponent(checkOut)
        : '');

      if (datesButton) {
        datesButton.disabled = true;
      }

      sayDates('Checking those nights…');

      fetch(url, { headers: { Accept: 'application/json' } })
        .then(function (response) {
          return response.ok ? response.json() : null;
        })
        .then(function (data) {
          if (datesButton) {
            datesButton.disabled = false;
          }

          if (!data || !data.resorts) {
            // The figures already on screen are still the server's own,
            // so they are left alone rather than blanked out.
            sayDates('Those nights could not be checked just now. Showing the figures from when this page loaded.', true);

            return;
          }

          projected = !!data.projected;

          var byId = {};
          data.resorts.forEach(function (row) {
            byId[row.id] = row;
          });

          points.forEach(function (point) {
            if (point.type !== 'resort') {
              return;
            }

            var row = byId[point.id];
            point.occupancy = row ? row : null;
            repaint(point);
          });

          var free = data.resorts.filter(function (row) {
            return row.status === 'available' || row.status === 'busy';
          }).length;

          sayDates(projected
            ? plural(data.nights, 'night') + ' from ' + data.check_in + ': ' +
              free + ' of ' + plural(data.resorts.length, 'resort') + ' with rooms free.'
            : 'Showing who is staying tonight.');

          if (datesClear) {
            datesClear.hidden = !projected;
          }
        })
        .catch(function () {
          if (datesButton) {
            datesButton.disabled = false;
          }

          sayDates('Those nights could not be checked just now. Showing the figures from when this page loaded.', true);
        });
    }

    if (dateForm && occupancyUrl) {
      dateForm.addEventListener('submit', function (event) {
        event.preventDefault();

        var from = checkInField ? checkInField.value : '';
        var to = checkOutField ? checkOutField.value : '';

        if (!from || !to) {
          sayDates('Choose both a check-in and a check-out date.', true);

          return;
        }

        if (to <= from) {
          sayDates('The check-out date must be after the check-in date.', true);

          return;
        }

        loadOccupancy(from, to);
      });

      // Check-out can never be on or before check-in, so the picker says so
      // rather than letting the form be submitted and refused.
      if (checkInField && checkOutField) {
        checkInField.addEventListener('change', function () {
          if (!checkInField.value) {
            return;
          }

          var next = new Date(checkInField.value + 'T00:00:00');
          next.setDate(next.getDate() + 1);

          var floor = next.toISOString().slice(0, 10);
          checkOutField.min = floor;

          if (checkOutField.value && checkOutField.value < floor) {
            checkOutField.value = floor;
          }
        });
      }

      if (datesClear) {
        datesClear.addEventListener('click', function () {
          if (checkInField) { checkInField.value = ''; }
          if (checkOutField) { checkOutField.value = ''; }

          datesClear.hidden = true;
          loadOccupancy(null, null);
        });
      }

      // Dates carried in on the URL are honoured on load, so a shared link
      // opens on the same nights the sender was looking at.
      if (checkInField && checkOutField && checkInField.value && checkOutField.value) {
        loadOccupancy(checkInField.value, checkOutField.value);
      }
    }

    /* Barangays ---------------------------------------------------------- */

    /*
     * Name labels rather than pins. A barangay is an area, and dropping a
     * teardrop on one point would claim a precision nobody here has: most of
     * these are placed from a landmark, not a surveyed centre. A label sitting
     * over the general area says what it is and does not pretend otherwise.
     */
    var barangays = JSON.parse(element.dataset.barangays || '[]');
    var overlays = { 'Resorts': layers.resort, 'Destinations': layers.destination };

    // By id, so the list beside the map can show a barangay even where
    // there is no outline to lift. Without this, choosing a barangay name
    // in a municipality whose boundaries have not been supplied yet does
    // nothing at all.
    var barangayMarkers = {};
    var barangayById = {};

    /*
     * Tells the page which barangay is showing, so a panel outside the
     * map can follow the map without reaching into it. Nothing here
     * depends on anybody listening: the map works the same alone.
     */
    function announceBarangay(id) {
      element.dispatchEvent(new CustomEvent('vd:barangay', {
        bubbles: true,
        detail: id ? (barangayById[id] || { id: id }) : null,
      }));
    }

    if (barangays.length) {
      var barangayLayer = L.layerGroup().addTo(map);

      barangays.forEach(function (barangay) {
        barangayById[barangay.id] = barangay;
        var classes = 'vd-brgy' + (barangay.approximate ? ' vd-brgy--approx' : '');

        barangayMarkers[barangay.id] = L.marker([barangay.lat, barangay.lng], {
          icon: L.divIcon({
            className: 'vd-brgy-wrap',
            // textContent is not available on a divIcon, so the one piece of
            // data in here is escaped by hand before it becomes markup.
            html: '<span class="' + classes + '">' + escapeHtml(barangay.name) + '</span>',
            iconSize: [0, 0],
            iconAnchor: [0, 0],
          }),
          // Below the resort and tourist spot pins, which are what a visitor
          // came to click.
          zIndexOffset: -500,
          interactive: true,
          title: 'Barangay ' + barangay.name,
        })
          .bindPopup(barangayPopup(barangay))
          .addTo(barangayLayer);

        // A label is the only handle on a barangay when no outline has
        // been supplied, so tapping one has to tell the panel too.
        barangayMarkers[barangay.id].on('click', function () {
          announceBarangay(barangay.id);
        });
      });

      overlays['Barangay names'] = barangayLayer;
    }

    /* Barangay boundaries -------------------------------------------------- */

    /*
     * The outlines, when the office has supplied them.
     *
     * Its own overlay, deliberately NOT part of the basemap: the lines
     * have to be readable over the street map and over the imagery, and
     * a boundary welded to one of them would vanish on the other. It
     * draws above the tiles and below every pin, so it never gets
     * between a visitor and the resort they are trying to tap.
     */
    var shapes = JSON.parse(element.dataset.barangayShapes || 'null');
    var boundaryLayer = null;
    var selectedBarangay = null;

    function boundaryStyle(selected) {
      return selected
        ? { color: '#0B6462', weight: 3, opacity: 1, fillColor: '#0F7C7A', fillOpacity: .22 }
        : { color: '#0D3B47', weight: 1.5, opacity: .7, fillColor: '#0F7C7A', fillOpacity: .06 };
    }

    /*
     * Lifts one barangay out of the rest. Called when its outline is
     * clicked and when its name is chosen from the list beside the map,
     * so the two stay in step.
     */
    function highlightBarangay(id) {
      if (!boundaryLayer) {
        return;
      }

      selectedBarangay = selectedBarangay === id ? null : id;

      boundaryLayer.eachLayer(function (layer) {
        var on = selectedBarangay !== null
          && layer.feature.properties.id === selectedBarangay;

        layer.setStyle(boundaryStyle(on));

        if (on) {
          layer.bringToFront();
          map.fitBounds(layer.getBounds(), { padding: [30, 30] });
        }
      });

      announceBarangay(selectedBarangay);
    }

    if (shapes && shapes.features && shapes.features.length) {
      boundaryLayer = L.geoJSON(shapes, {
        style: function () {
          return boundaryStyle(false);
        },
        onEachFeature: function (feature, layer) {
          var name = feature.properties.name;

          // A permanent label, so the map reads as a map of barangays
          // rather than a set of unexplained outlines.
          layer.bindTooltip(name, {
            permanent: true,
            direction: 'center',
            className: 'vd-brgy-label',
          });

          layer.on('click', function () {
            highlightBarangay(feature.properties.id);
          });

          // Reachable without a mouse: the outlines are the only way to
          // pick a barangay on the map itself.
          layer.on('keypress', function (event) {
            if (event.originalEvent.key === 'Enter') {
              highlightBarangay(feature.properties.id);
            }
          });
        },
      }).addTo(map);

      // Under the pins, over the tiles.
      boundaryLayer.bringToBack();

      overlays['Barangay boundaries'] = boundaryLayer;
    }

    /* Filling stations ---------------------------------------------------- */

    /*
     * Where to fill up, from OpenStreetMap. A small pin rather than a
     * teardrop: this is something you pass on the way, not somewhere you
     * are going, and it must not compete with the resorts and spots a
     * visitor actually came to click.
     *
     * Its own overlay, so anyone who does not care can switch it off.
     */
    var fuel = JSON.parse(element.dataset.fuel || '[]');

    if (fuel.length) {
      var fuelLayer = L.layerGroup().addTo(map);

      fuel.forEach(function (station) {
        L.marker([station.lat, station.lng], {
          icon: L.divIcon({
            className: 'vd-pin-wrap',
            html: '<span class="vd-pin vd-pin--fuel"><i class="bi bi-fuel-pump-fill"></i></span>',
            iconSize: [24, 24],
            iconAnchor: [12, 24],
            popupAnchor: [0, -22],
          }),
          zIndexOffset: -300,
          title: station.name,
        })
          .bindPopup(fuelPopup(station))
          .addTo(fuelLayer);
      });

      overlays['Filling stations'] = fuelLayer;
    }

    /*
     * What to show ON the map, now that choosing the map itself has its
     * own control. Overlays only.
     *
     * Folded away on a narrow screen. Expanded it is four or five rows
     * in the corner, which on a phone is a sizeable piece of the map a
     * visitor came to look at; on a laptop there is room for it.
     */
    L.control.layers(null, overlays, {
      collapsed: window.matchMedia('(max-width: 767.98px)').matches,
    }).addTo(map);

    /* Routing ------------------------------------------------------------ */

    /*
     * Asks the routing service for the driving route between any two places,
     * draws it, and reports the road distance and time.
     *
     * "from" and "to" are each {name, lat, lng}, so this serves both the
     * popup's "Route from me" and the trip planner's barangay-to-resort. Only
     * the two pairs of coordinates are sent. If the service cannot be reached
     * the straight-line figure is shown instead, clearly labelled, so the map
     * stays useful with no network and no third party.
     */
    /*
     * Take the route off the map.
     *
     * A drawn route used to stay until another replaced it, so a
     * visitor who had looked up one way and then wanted to read the
     * coast underneath had no way to put the line away.
     *
     * The climb panel goes with it. It describes THIS route, and
     * leaving it open beside no line is a figure about nothing.
     */
    /*
     * Let go of the GPS.
     *
     * Safe to call when nothing is running — the route clear, the
     * second press of the button and the page unload all go through
     * here, and only one of them will have a watch to cancel.
     */
    function stopFollowing() {
      if (followWatch !== null) {
        navigator.geolocation.clearWatch(followWatch);
        followWatch = null;
      }

      if (followButton) {
        followButton.setAttribute('aria-pressed', 'false');
        followButton.classList.remove('is-on');
        followButton.innerHTML = '<i class="bi bi-broadcast me-1" aria-hidden="true"></i>';
        followButton.appendChild(document.createTextNode('Follow my location'));
      }
    }

    function clearRoute() {
      if (routeLine) {
        map.removeLayer(routeLine);
        routeLine = null;
      }

      if (profileBox) {
        profileBox.hidden = true;
      }

      if (clearRouteButton) {
        clearRouteButton.hidden = true;
      }

      report('');
    }

    function routeBetween(from, to, button) {
      if (!from || !to || !routingUrl) {
        return;
      }

      if (from.lat === to.lat && from.lng === to.lng) {
        report('Choose two different places.', true);

        return;
      }

      var original = button ? button.textContent : null;

      if (button) {
        button.disabled = true;
        button.textContent = 'Finding road…';
      }

      var path = from.lng + ',' + from.lat + ';' + to.lng + ',' + to.lat;
      var url = routingUrl + path + '?overview=full&geometries=geojson';
      var journey = from.name + ' to ' + to.name;

      var done = function (text, failed) {
        if (button) {
          button.disabled = false;
          button.textContent = original;
        }
        report(text, failed);
      };

      fetch(url, { headers: { Accept: 'application/json' } })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Routing service answered ' + response.status);
          }
          return response.json();
        })
        .then(function (data) {
          if (!data.routes || !data.routes.length) {
            throw new Error('No road route');
          }

          var best = data.routes[0];

          if (routeLine) {
            map.removeLayer(routeLine);
          }

          /*
           * Two lines, not one: a white casing with the teal road on
           * top of it.
           *
           * A single teal line is legible on the street map's pale
           * ground and very nearly invisible over dark water or forest
           * on the imagery — which is where somebody is most likely to
           * be looking at a route. The casing gives it an edge against
           * whatever is underneath, the way every road atlas draws a
           * road.
           */
          routeLine = L.featureGroup([
            L.geoJSON(best.geometry, {
              style: { className: 'vd-route vd-route--casing', color: '#FFFFFF', weight: 9, opacity: .9 },
            }),
            L.geoJSON(best.geometry, {
              style: { className: 'vd-route', color: '#0F7C7A', weight: 5, opacity: .95 },
            }),
          ]).addTo(map);

          map.fitBounds(routeLine.getBounds(), { padding: [50, 50] });

          // There is a line to clear now, so offer the way to clear it.
          if (clearRouteButton) {
            clearRouteButton.hidden = false;
          }

          var summary = journey + ': ' + formatKm(best.distance / 1000) +
            ' by road, about ' + formatMinutes(best.duration) + ' driving.';

          done(summary);

          // The climb is a second question, asked only once the route itself
          // is drawn and reported. It may fail, and nothing above depends on it.
          describeClimb(best.geometry, summary, best.distance);
        })
        .catch(function () {
          done(journey + ': ' + formatKm(straightLineKm(from, to)) +
            ' in a straight line. The road route could not be fetched just now.', true);
        });
    }

    /* How much the road climbs ------------------------------------------- */

    var profileUrl = element.dataset.routeProfileUrl || null;
    var profileBox = scope.querySelector('[data-route-profile]');

    /*
     * A route can run to a thousand points; the height of every one of them
     * is neither needed nor polite to ask for. Evenly spaced samples, the two
     * ends always kept, give the same shape at a fraction of the cost.
     */
    /*
     * How many samples to take along a route of this length.
     *
     * Aiming at a spacing rather than a fixed count, because the server only
     * calls a stretch a climb once it is long enough for the height model's
     * error to be small against it. A fixed 90 samples puts them 30 m apart
     * on a short road, every stretch falls under that bar, and a road that
     * plainly climbs is painted flat all the way — which is exactly what the
     * 2.7 km road out of Poblacion did before this.
     */
    function sampleCount(metres) {
      return Math.max(8, Math.min(90, Math.round(metres / 200)));
    }

    function sample(coordinates, most) {
      if (coordinates.length <= most) {
        return coordinates.map(function (pair, index) { return index; });
      }

      var picked = [];
      var step = (coordinates.length - 1) / (most - 1);

      for (var i = 0; i < most; i++) {
        picked.push(Math.round(i * step));
      }

      return picked;
    }

    /*
     * The colour a stretch of road is drawn in.
     *
     * Three bands rather than a smooth scale, because this is read at a
     * glance from a moving thumb: flat, a climb, a steep climb, and the same
     * two downhill. The numbers that matter are written underneath in words.
     */
    function slopeStyle(grade) {
      if (grade === null || Math.abs(grade) < 2) {
        return { colour: '#0F7C7A', band: 'level' };          // the usual route teal
      }

      if (grade > 0) {
        return grade >= 6
          ? { colour: '#B3321F', band: 'steep climb' }
          : { colour: '#E08A1E', band: 'climb' };
      }

      return grade <= -6
        ? { colour: '#2F5FA8', band: 'steep drop' }
        : { colour: '#5E9BD6', band: 'drop' };
    }

    /* A chevron laid on the road, pointing the way the slope goes. */
    function slopeMarker(at, grade, style) {
      return L.marker(at, {
        icon: L.divIcon({
          className: 'vd-slope-wrap',
          html: '<span class="vd-slope" style="background:' + style.colour + '">'
            + '<i class="bi bi-chevron-' + (grade > 0 ? 'up' : 'down') + '"></i></span>',
          iconSize: [18, 18],
          iconAnchor: [9, 9],
        }),
        zIndexOffset: -200,
        title: (grade > 0 ? 'Climbing about ' : 'Dropping about ') + Math.abs(grade) + '%',
      }).bindPopup((grade > 0 ? 'Uphill' : 'Downhill') + ', about ' + Math.abs(grade) + '%');
    }

    /* A small line drawing of the road's height, built as SVG nodes. */
    function drawProfile(points) {
      var width = 600;
      var height = 90;
      var pad = 4;

      var low = Math.min.apply(null, points.map(function (p) { return p.m; }));
      var high = Math.max.apply(null, points.map(function (p) { return p.m; }));
      var span = Math.max(high - low, 10);     // a flat road still gets a line
      var far = points[points.length - 1].at || 1;

      var steps = points.map(function (p) {
        var x = pad + (p.at / far) * (width - pad * 2);
        var y = height - pad - ((p.m - low) / span) * (height - pad * 2);

        return x.toFixed(1) + ',' + y.toFixed(1);
      });

      var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
      svg.setAttribute('class', 'vd-profile__chart');
      svg.setAttribute('role', 'img');
      svg.setAttribute('preserveAspectRatio', 'none');

      var fill = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
      fill.setAttribute('class', 'vd-profile__fill');
      fill.setAttribute('points',
        pad + ',' + (height - pad) + ' ' + steps.join(' ') + ' ' + (width - pad) + ',' + (height - pad));

      var line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
      line.setAttribute('class', 'vd-profile__line');
      line.setAttribute('points', steps.join(' '));

      svg.appendChild(fill);
      svg.appendChild(line);

      return svg;
    }

    /*
     * Repaints the drawn route so the road itself shows where it climbs.
     *
     * The plain teal line is replaced by one coloured stretch per sampled
     * segment, each drawn along the ORIGINAL geometry so the road keeps its
     * bends, with a chevron laid on anything worth noticing. Totals in words
     * are easy to skim past; a red climb on the map is not.
     */
    function paintSlopes(geometry, marks, measured) {
      if (!routeLine || measured.length !== marks.length) {
        return;
      }

      map.removeLayer(routeLine);

      var painted = L.layerGroup();

      // The same white casing the plain route gets, laid down first so
      // every coloured stretch sits on top of it. Without it the gentle
      // stretches are a thin teal line over dark water again.
      L.polyline(
        geometry.coordinates.map(function (pair) { return [pair[1], pair[0]]; }),
        { className: 'vd-route vd-route--casing', color: '#FFFFFF', weight: 9, opacity: .9 }
      ).addTo(painted);

      for (var i = 1; i < marks.length; i++) {
        var grade = measured[i].grade;
        var style = slopeStyle(grade);

        // Every original point between the two samples, so bends survive.
        var stretch = geometry.coordinates
          .slice(marks[i - 1], marks[i] + 1)
          .map(function (pair) { return [pair[1], pair[0]]; });

        if (stretch.length < 2) {
          continue;
        }

        L.polyline(stretch, {
          className: 'vd-route',
          color: style.colour,
          weight: 5,
          opacity: .9,
        }).addTo(painted);

        // A marker on every slope would bury the road it describes.
        if (grade !== null && Math.abs(grade) >= 4) {
          slopeMarker(stretch[Math.floor(stretch.length / 2)], grade, style).addTo(painted);
        }
      }

      painted.addTo(map);
      routeLine = painted;   // so the next route still clears this one
    }

    function describeClimb(geometry, summary, metres) {
      if (!profileUrl || !profileBox || !geometry || !geometry.coordinates) {
        return;
      }

      var body = profileBox.querySelector('[data-route-profile-body]') || profileBox;
      var key = profileBox.querySelector('[data-route-profile-key]');

      profileBox.hidden = false;
      body.textContent = 'Working out the climbs…';

      if (key) {
        // Nothing is coloured yet, so the key would explain nothing.
        key.hidden = true;
      }

      // The indexes are kept, not just the points: each coloured stretch is
      // then drawn along the ORIGINAL geometry between two samples, so the
      // road keeps its bends instead of being cut into straight hops.
      var marks = sample(geometry.coordinates, sampleCount(metres));
      var path = marks.map(function (index) {
        return { lat: geometry.coordinates[index][1], lng: geometry.coordinates[index][0] };
      });

      fetch(profileUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
        },
        body: JSON.stringify({ points: path }),
      })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) {
          if (!data || !data.found) {
            // The road and the driving time are already on screen and still
            // true. Only the height is missing, so only the height is dropped.
            profileBox.hidden = true;

            return;
          }

          paintSlopes(geometry, marks, data.points);

          body.textContent = '';

          if (key) {
            key.hidden = false;
          }

          var words = document.createElement('p');
          words.className = 'vd-profile__words';

          var flat = data.ascent < 10 && data.descent < 10;

          words.textContent = flat
            ? 'This road is near enough flat: it stays between ' + data.low +
              ' and ' + data.high + ' m above sea level.'
            : 'Climbs ' + data.ascent + ' m in total and drops ' + data.descent +
              ' m, between ' + data.low + ' and ' + data.high + ' m above sea level.' +
              (data.steepest
                ? ' Steepest stretch about ' + data.steepest + '%' +
                  (data.steepest_falls ? ' downhill.' : ' uphill.')
                : '');

          body.appendChild(words);

          var chart = drawProfile(data.points);
          // The drawing is decoration; the sentence above carries the facts.
          chart.setAttribute('aria-label', summary + ' ' + words.textContent);
          body.appendChild(chart);
        })
        .catch(function () {
          profileBox.hidden = true;
        });
    }

    /* Kept for the popup button, which always starts where the visitor is. */
    function drawRoute(point, button) {
      if (!here) {
        return;
      }

      routeBetween({ name: 'You', lat: here.lat, lng: here.lng }, point, button);
    }

    /* The visitor's own position ----------------------------------------- */

    var readout = scope.querySelector('[data-map-readout]');

    function report(text, failed) {
      if (!readout) {
        return;
      }

      readout.hidden = false;
      readout.textContent = text;
      readout.classList.toggle('is-warning', failed === true);
    }

    /*
     * Fills every "X km away" in the list beside the map.
     *
     * Straight-line first, because it costs nothing and appears the instant a
     * location is known. Road distance replaces it when the routing service
     * answers, and if it never does, the straight-line figure simply stays,
     * labelled for what it is.
     */
    function showDistances() {
      document.querySelectorAll('[data-map-distance]').forEach(function (slot) {
        var point = points.find(function (candidate) {
          return candidate.id === slot.dataset.mapDistance;
        });

        if (!point) {
          return;
        }

        var road = byRoad[point.id];

        if (road) {
          slot.textContent = formatKm(road.km) + ' by road · ' + formatMinutes(road.seconds);
          slot.classList.add('is-road');
        } else {
          slot.textContent = formatKm(straightLineKm(here, point)) + ' in a straight line';
          slot.classList.remove('is-road');
        }

        slot.hidden = false;
      });
    }

    /*
     * Asks the routing service for the road distance to EVERY pin in one
     * request, rather than one request per resort.
     *
     * The table service answers with a row of distances from the first
     * coordinate (the visitor) to all the rest, in the order they were sent.
     * A point with no road to it comes back null, which is why each one is
     * checked rather than assumed.
     */
    function loadRoadDistances() {
      if (!here || !tableUrl || points.length === 0) {
        return;
      }

      // Nearest first, so if the list has to be trimmed it is the far ones
      // that go, not whichever happened to be created first.
      var asked = points
        .map(function (point) {
          return { point: point, km: straightLineKm(here, point) };
        })
        .sort(function (a, b) { return a.km - b.km; })
        .slice(0, tableLimit)
        .map(function (entry) { return entry.point; });

      var coordinates = [here.lng + ',' + here.lat];
      asked.forEach(function (point) {
        coordinates.push(point.lng + ',' + point.lat);
      });

      var url = tableUrl + coordinates.join(';') + '?sources=0&annotations=distance,duration';

      fetch(url, { headers: { Accept: 'application/json' } })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Routing service answered ' + response.status);
          }
          return response.json();
        })
        .then(function (data) {
          var distances = (data.distances || [])[0];
          var durations = (data.durations || [])[0];

          if (!distances) {
            throw new Error('No distances returned');
          }

          var found = 0;

          asked.forEach(function (point, index) {
            // Index 0 is the visitor themselves, so answers start at 1.
            var metres = distances[index + 1];
            var seconds = durations ? durations[index + 1] : null;

            if (typeof metres === 'number') {
              byRoad[point.id] = { km: metres / 1000, seconds: seconds || 0 };
              found++;
            }
          });

          if (found > 0) {
            showDistances();
            report('Distances are by road from where you are, with driving time. '
              + 'Open a pin and choose "Route from me" to see the way there.');
          }
        })
        .catch(function () {
          // The public routing service refuses requests from time to time.
          // Nothing breaks: the straight-line figures are already on screen.
          report('Showing straight-line distances. The road distances could not be '
            + 'fetched just now, so these are shorter than the actual drive.', true);
        });
    }

    /*
     * THE SPOT YOU SET YOURSELF, KEPT.
     *
     * Dragging the pin fixes the map once. On a laptop the browser gets
     * it wrong the same way every time — it is placed by the networks
     * around it, and those do not move — so without this the same drag
     * has to be repeated on every visit.
     *
     * Kept in this browser only. It is never sent anywhere, never
     * reaches the server, and is offered rather than applied: a spot
     * saved last month is not where somebody is standing today, so the
     * map must not quietly assume it.
     */
    var SAVED_PLACE_KEY = 'vd.map.myplace';

    function rememberPlace(lat, lng) {
      try {
        window.localStorage.setItem(SAVED_PLACE_KEY, JSON.stringify({
          lat: lat,
          lng: lng,
          savedAt: Date.now(),
        }));
      } catch (error) {
        // Private browsing, or storage switched off. Dragging still
        // works; it just will not be remembered next time.
      }
    }

    function rememberedPlace() {
      try {
        var raw = window.localStorage.getItem(SAVED_PLACE_KEY);
        var saved = raw ? JSON.parse(raw) : null;

        return (saved && typeof saved.lat === 'number' && typeof saved.lng === 'number')
          ? saved
          : null;
      } catch (error) {
        return null;
      }
    }

    function forgetPlace() {
      try {
        window.localStorage.removeItem(SAVED_PLACE_KEY);
      } catch (error) {
        // Nothing to do; the button simply disappears either way.
      }
    }

    /*
     * Draws the visitor at a position, and works out what is near them.
     *
     * Separated from locate() because a found position and a corrected
     * one have to behave identically: the pin, the ring, the distances
     * and the route all follow whichever is current. The first press
     * used to be the only way to set this, which is why a browser that
     * guessed wrong could not be argued with.
     *
     * accuracy is null for a position the visitor placed themselves —
     * they know where they are standing better than a ring can say.
     */
    function placeMe(lat, lng, accuracy, corrected) {
      here = { lat: lat, lng: lng };

      if (hereMarker) {
        map.removeLayer(hereMarker);
      }

      // The previous ring too, or pressing the button twice leaves the old
      // one behind and the map slowly fills with circles.
      if (hereCircle) {
        map.removeLayer(hereCircle);
      }

      hereMarker = L.marker([lat, lng], {
        icon: hereIcon(),
        title: corrected ? 'Where you said you are' : 'Your location',

        /*
         * THE ANSWER TO A BROWSER THAT PUTS YOU IN THE WRONG PLACE.
         *
         * Wifi positioning places a laptop by which networks it can
         * see, and in a municipality with few mapped networks that is
         * routinely the wrong barangay — sometimes the wrong province.
         * No amount of asking again fixes it. The visitor can see the
         * map and knows where they are standing, so they are allowed
         * to say: drag the pin, and every distance follows it.
         */
        draggable: true,
        autoPan: true,
      }).addTo(map);

      hereMarker.bindPopup(herePopup(
        { latitude: lat, longitude: lng, accuracy: accuracy },
        corrected
      ));

      // Only for a reading the browser gave. A ring around a pin the
      // visitor placed by hand would be inventing a margin of error for
      // a statement that has none.
      if (accuracy && accuracy > 0) {
        hereCircle = L.circle([lat, lng], {
          radius: accuracy,
          color: '#0F7C7A',
          weight: 1,
          fillOpacity: .08,
        }).addTo(map);
      } else {
        hereCircle = null;
      }

      hereMarker.on('dragend', function () {
        var moved = hereMarker.getLatLng();

        // Placed by hand, so no accuracy ring and no claim of one.
        placeMe(moved.lat, moved.lng, null, true);

        // Kept, so the same correction is not needed every visit.
        rememberPlace(moved.lat, moved.lng);
        showSavedPlaceButton();

        report('Moved to where you said you are, and saved for next time. '
          + 'Distances are measured from here now. Working out the road distances…');

        loadRoadDistances();
      });

      showDistances();
    }

    /** The closest pin to a position, or null when the map is empty. */
    function nearestTo(from) {
      var nearest = null;

      points.forEach(function (point) {
        var km = straightLineKm(from, point);

        if (!nearest || km < nearest.km) {
          nearest = { point: point, km: km };
        }
      });

      return nearest;
    }

    function locate(button) {
      if (!navigator.geolocation) {
        report('This browser cannot share a location.', true);
        return;
      }

      var blocked = geo.insecureOriginMessage();

      if (blocked) {
        report(blocked, true);

        return;
      }

      var original = button.textContent;
      button.disabled = true;
      button.textContent = 'Locating…';

      /*
       * WHY THIS WATCHES INSTEAD OF ASKING ONCE.
       *
       * The first fix a phone hands back is usually the cheap one —
       * cell tower or wifi, hundreds of metres out — and the GPS
       * reading arrives a few seconds later. getCurrentPosition returns
       * that first answer and never mentions the better one, which is
       * how somebody ends up pinned in the next barangay.
       *
       * So: keep watching, keep the most accurate reading seen, and
       * stop as soon as it is good enough or the time is up.
       */
      var GOOD_ENOUGH_METRES = 40;
      var PATIENCE_MS = 9000;

      var best = null;
      var watch = null;
      var timer = null;

      function finish() {
        if (watch !== null) {
          navigator.geolocation.clearWatch(watch);
          watch = null;
        }

        window.clearTimeout(timer);

        button.disabled = false;
        button.textContent = original;

        if (!best) {
          report('Your location could not be found just now.', true);

          return;
        }

        placeMe(best.coords.latitude, best.coords.longitude, best.coords.accuracy, false);

        var quality = geo.describeAccuracy(best.coords.accuracy);
        var inDasol = geo.withinBounds(here, bounds);

        /*
         * Centred on the visitor at a zoom that shows the whole ring,
         * so the first thing they see is where they have been put and
         * how sure the browser is. Fitting to a distant resort instead
         * used to zoom so far out that a reading a kilometre wrong
         * looked perfectly reasonable.
         */
        if (hereCircle) {
          map.fitBounds(hereCircle.getBounds(), { padding: [40, 40], maxZoom: 17 });
        } else {
          map.setView([here.lat, here.lng], 16);
        }

        var nearest = nearestTo(here);

        /*
         * Said plainly, and said differently when it is not to be
         * trusted. "Found you" over a reading two kilometres wide is
         * the sentence that makes somebody believe a wrong pin.
         */
        if (!inDasol) {
          report('Your browser places you outside Dasol, ' + quality.text
            + '. If that is wrong, drag the blue pin to where you really are '
            + 'and the distances will follow it.', true);

          return;
        }

        if (quality.grade === 'poor' || quality.grade === 'unknown') {
          /*
           * Past a couple of kilometres the browser is not measuring
           * anything. There is no GPS and no nearby network it knows,
           * so it has fallen back to the internet connection, which
           * points at the provider and not at the person. Saying which
           * of the two happened is the difference between "try again"
           * and "stop trying and drag the pin".
           */
          var fromTheInternet = best.coords.accuracy >= 2000;

          report((fromTheInternet
            ? 'This device has no GPS fix, so your browser placed you from the '
              + 'internet connection — that points at your provider, not at you ('
              + quality.text + '). '
            : 'Found a rough position, ' + quality.text
              + '. That is wide enough to be the wrong barangay. ')
            + 'Drag the blue pin to where you really are; it will be saved for next time.', true);
        } else if (nearest) {
          report('Found you, ' + quality.text + '. Nearest is ' + nearest.point.name
            + ', ' + formatKm(nearest.km) + ' away in a straight line. '
            + 'Drag the blue pin if that is not where you are. '
            + 'Working out the road distances…');
        } else {
          report('Found you, ' + quality.text
            + ', but there is nothing on the map to measure to yet.');
        }

        // Straight-line figures are already on screen; this upgrades them.
        loadRoadDistances();
      }

      watch = navigator.geolocation.watchPosition(
        function (position) {
          // Keep the sharpest reading of the attempt, not the latest:
          // accuracy wanders, and a later fix is not always a better one.
          if (!best || position.coords.accuracy < best.coords.accuracy) {
            best = position;
          }

          if (best.coords.accuracy <= GOOD_ENOUGH_METRES) {
            finish();
          }
        },
        function (error) {
          // A refusal is final; a timeout with a reading already in hand
          // is not, so that reading is still used.
          if (error.code === error.PERMISSION_DENIED) {
            if (watch !== null) {
              navigator.geolocation.clearWatch(watch);
              watch = null;
            }

            window.clearTimeout(timer);

            button.disabled = false;
            button.textContent = original;

            report('Location permission was refused, so distances cannot be measured. '
              + 'You can allow it from the icon in the address bar.', true);

            return;
          }

          if (!best) {
            finish();
          }
        },
        // maximumAge 0: never a position from before the button was
        // pressed. A cached fix is how somebody who has driven across
        // town is shown where they had breakfast.
        { enableHighAccuracy: true, timeout: PATIENCE_MS, maximumAge: 0 }
      );

      timer = window.setTimeout(finish, PATIENCE_MS);
    }

    /*
     * "Use the spot I set" — shown only once there is one.
     *
     * Offered, never applied on its own. A saved spot is where somebody
     * was standing when they set it, and assuming that is still true
     * would reintroduce exactly the wrong-pin problem it exists to fix.
     */
    function showSavedPlaceButton() {
      var saved = rememberedPlace();

      scope.querySelectorAll('[data-map-saved-place]').forEach(function (button) {
        button.hidden = !saved;
      });

      scope.querySelectorAll('[data-map-forget-place]').forEach(function (button) {
        button.hidden = !saved;
      });
    }

    if (canLocate) {
      scope.querySelectorAll('[data-map-locate]').forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function () {
          locate(button);
        });
      });

      /*
       * Clearing the route, and with it anything that described it.
       *
       * Also stops following. Somebody who has put the route away is
       * no longer navigating, and leaving the GPS running after that
       * drains a phone for nothing.
       */
      clearRouteButton = scope.querySelector('[data-map-clear-route]');

      if (clearRouteButton) {
        clearRouteButton.addEventListener('click', function () {
          stopFollowing();
          clearRoute();
        });
      }

      /*
       * FOLLOWING, which is the one thing here that keeps the GPS on.
       *
       * The locate button takes a handful of readings, keeps the
       * sharpest and lets go. That is right for "where am I" and wrong
       * for "I am walking there now", so this is the other mode: a
       * watch that moves the pin as the reading changes, started only
       * when asked and stopped on the second press, when the route is
       * cleared, or when the page goes away.
       */
      followButton = scope.querySelector('[data-map-follow]');

      if (followButton && navigator.geolocation) {
        followButton.hidden = false;

        followButton.addEventListener('click', function () {
          if (followWatch !== null) {
            stopFollowing();

            return;
          }

          var blocked = geo.insecureOriginMessage();

          if (blocked) {
            report(blocked, true);

            return;
          }

          followWatch = navigator.geolocation.watchPosition(
            function (position) {
              placeMe(
                position.coords.latitude,
                position.coords.longitude,
                position.coords.accuracy,
                false
              );

              /*
               * The pin moves; the map does not chase it. Panning
               * under somebody's thumb while they are reading the
               * route is the thing every mapping app gets complained
               * about, so the view is theirs until they recentre.
               */
            },
            function (error) {
              stopFollowing();

              report(
                error.code === error.PERMISSION_DENIED
                  ? 'Location permission was refused, so following is off. '
                    + 'You can allow it from the icon in the address bar.'
                  : 'Could not keep track of your location just now.',
                true
              );
            },
            { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 }
          );

          followButton.setAttribute('aria-pressed', 'true');
          followButton.classList.add('is-on');
          followButton.innerHTML = '<i class="bi bi-broadcast-pin me-1" aria-hidden="true"></i>';
          followButton.appendChild(document.createTextNode('Stop following'));

          report('Following your location. The pin moves as you do; the map stays where you put it.');
        });
      }

      // Nothing should keep the GPS awake once the page is gone.
      window.addEventListener('pagehide', stopFollowing);

      scope.querySelectorAll('[data-map-saved-place]').forEach(function (button) {
        button.addEventListener('click', function () {
          var saved = rememberedPlace();

          if (!saved) {
            return;
          }

          placeMe(saved.lat, saved.lng, null, true);
          map.setView([saved.lat, saved.lng], 16);

          report('Using the spot you set yourself. Drag the pin to move it. '
            + 'Working out the road distances…');

          loadRoadDistances();
        });
      });

      scope.querySelectorAll('[data-map-forget-place]').forEach(function (button) {
        button.addEventListener('click', function () {
          forgetPlace();
          showSavedPlaceButton();
          report('Forgotten. Press "My location" to let the browser try again.');
        });
      });

      showSavedPlaceButton();
    }

    /* From here to there -------------------------------------------------- */

    /*
     * The road between any two places on this map, not only between the
     * visitor and one pin. Somebody planning a day out wants the distance from
     * Barangay Tambobong to a resort in Poblacion, and until now the only
     * question the map could answer was "how far is that from where I am
     * standing" — useless to anyone planning from home.
     *
     * The menus are rendered by Blade, so they work before this file runs and
     * they list exactly what the server put on the map.
     */
    var planner = scope.querySelector('[data-map-trip]');

    if (planner && routingUrl) {
      var fromBox = planner.querySelector('[data-map-trip-from]');
      var toMenu = planner.querySelector('[data-map-trip-to]');
      var searchUrl = planner.dataset.placeSearch || null;
      var go = planner.querySelector('button[type="submit"]');

      // Every place on this map, by id and by name. The "To" menu sends an
      // id; the "From" box sends whatever was typed, which may be one of
      // these names or may be a town fifty kilometres away.
      var byId = {};
      var byName = {};

      var remember = function (place) {
        byId[place.id] = place;
        byName[place.name.toLowerCase()] = place;
      };

      points.forEach(remember);
      barangays.forEach(function (barangay) {
        remember(barangay);
        // The datalist offers barangays as "Brgy. Poblacion".
        byName['brgy. ' + barangay.name.toLowerCase()] = barangay;
      });

      var hereAs = function () {
        return here ? { name: 'Where you are', lat: here.lat, lng: here.lng } : null;
      };

      // Fills the box with the visitor's own position, so "from me" is still
      // one tap even though this is a text field now.
      var meButton = planner.querySelector('[data-map-trip-me]');

      if (meButton) {
        meButton.addEventListener('click', function () {
          if (!here) {
            report('Press "My location" first, so the map knows where you are.', true);

            return;
          }

          fromBox.value = 'Where you are';
        });
      }

      /*
       * Typed text to a point. A name already on this map is answered without
       * leaving the page; anything else is asked of the server, which caches
       * the answer and knows how to identify itself to the search service.
       */
      var resolveTyped = function (text) {
        var typed = (text || '').trim();

        if (typed === '') {
          return Promise.resolve(null);
        }

        if (typed.toLowerCase() === 'where you are') {
          return Promise.resolve(hereAs());
        }

        var known = byName[typed.toLowerCase()];

        if (known) {
          return Promise.resolve(known);
        }

        if (!searchUrl) {
          return Promise.resolve(null);
        }

        return fetch(searchUrl + '?q=' + encodeURIComponent(typed), {
          headers: { Accept: 'application/json' },
        })
          .then(function (response) { return response.ok ? response.json() : null; })
          .then(function (data) { return data && data.found ? data : null; })
          .catch(function () { return null; });
      };

      planner.addEventListener('submit', function (event) {
        event.preventDefault();

        var to = toMenu.value === 'me' ? hereAs() : byId[toMenu.value];

        if (!to) {
          report('Press "My location" first, so the map knows where you are.', true);

          return;
        }

        var typed = fromBox.value.trim();

        if (typed === '') {
          report('Type where you are starting from, or pick one of the places listed.', true);

          return;
        }

        var original = go.textContent;
        go.disabled = true;
        go.textContent = 'Looking…';

        resolveTyped(typed).then(function (from) {
          go.disabled = false;
          go.textContent = original;

          if (!from) {
            report(typed.toLowerCase() === 'where you are'
              ? 'Press "My location" first, so the map knows where you are.'
              : 'No place called "' + typed + '" could be found. Try the town name, '
                + 'or pick one of the places listed in the box.', true);

            return;
          }

          routeBetween(from, to, go);
        });
      });
    }

    /* The list beside the map -------------------------------------------- */

    document.querySelectorAll('[data-map-focus]').forEach(function (button) {
      button.addEventListener('click', function () {
        var id = button.dataset.mapFocus;

        /*
         * A barangay with an outline is answered by lifting the outline
         * rather than by opening a label's popup. Choosing "Brgy.
         * Poblacion" in the list should show Poblacion, not drop a pin
         * on a school in the middle of it.
         */
        if (boundaryLayer && id.indexOf('barangay-') === 0) {
          var found = false;

          boundaryLayer.eachLayer(function (layer) {
            if (layer.feature.properties.id === id) {
              found = true;
            }
          });

          if (found) {
            // Never a toggle from the list: choosing a name should
            // always show it, not hide what is already showing.
            selectedBarangay = null;
            highlightBarangay(id);
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });

            return;
          }
        }

        /*
         * No outline for it — either none have been supplied, or this
         * one is not in the file. The name label is then the only handle
         * there is, and going to it still answers "where is Tambobong".
         */
        if (barangayMarkers[id]) {
          map.setView(barangayMarkers[id].getLatLng(), 14);
          barangayMarkers[id].openPopup();
          announceBarangay(id);
          element.scrollIntoView({ behavior: 'smooth', block: 'center' });

          return;
        }

        var marker = markers[button.dataset.mapFocus];

        if (!marker) {
          return;
        }

        var layer = points.find(function (point) { return point.id === button.dataset.mapFocus; });
        if (layer && layers[layer.type] && !map.hasLayer(layers[layer.type])) {
          map.addLayer(layers[layer.type]);
        }

        map.setView(marker.getLatLng(), 15);
        marker.openPopup();
        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    });

    /*
     * Leaflet measures its container once. Anything on the page that
     * changes that container's size — a panel folding away, the bigger
     * view opening, a phone turning — fires this, and the map measures
     * again. Without it the tiles draw into the old rectangle and leave
     * grey where the new one grew.
     *
     * An event rather than a global function, so a page can rearrange
     * itself around the map without either knowing about the other.
     */
    /*
     * HOW CLOSE THE MAP IS, AS A CLASS ON THE CONTAINER.
     *
     * Zoomed out to the whole municipality, the coastal strip carries
     * resorts, tourist spots, filling stations and barangay names all
     * within a few hundred metres of each other, and they pile up into
     * an unreadable heap. Zoomed in there is room for all of it.
     *
     * The CSS shrinks the secondary pins and the barangay lettering in
     * the far band and restores them in the near one. Deliberately NOT
     * done by adding and removing layers: that would fight the layers
     * control, and a visitor who switched filling stations on would
     * find them gone again after a pinch. Nothing is created, nothing
     * is destroyed, nothing is duplicated — the same markers are drawn
     * smaller.
     */
    function showZoomBand() {
      var zoom = map.getZoom();

      element.classList.toggle('is-zoom-far', zoom <= 12);
      element.classList.toggle('is-zoom-mid', zoom > 12 && zoom < 15);
      element.classList.toggle('is-zoom-near', zoom >= 15);
    }

    map.on('zoomend', showZoomBand);
    showZoomBand();

    element.addEventListener('vd:resize', function () {
      map.invalidateSize();
    });

    window.setTimeout(function () {
      map.invalidateSize();
    }, 200);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-visit-map]').forEach(setUp);
  });
})();
