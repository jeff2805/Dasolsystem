<?php

namespace Tests\Feature\Map;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Three base maps: the street map, the photograph, and the photograph
 * with names on it.
 *
 * There used to be two. "Satellite" always carried the transparent
 * reference tiles, so a visitor who wanted to look at the coastline
 * itself — the shape of a cove, where the sand ends — could not get the
 * lettering off it. Hybrid is that pairing made explicit, and Satellite
 * now means the imagery alone.
 *
 * The trap this guards is the Content Security Policy. Tile hosts are
 * read from config and written into img-src by SecurityHeaders; a layer
 * the map asks for that the policy does not name is refused by the
 * browser with nothing on the page to show for it.
 */
class BasemapChoiceTest extends TestCase
{
    use RefreshDatabase;

    private function mapJs(): string
    {
        return file_get_contents(public_path('js/map.js'));
    }

    public function test_the_map_offers_street_satellite_and_hybrid(): void
    {
        $script = $this->mapJs();

        foreach (['streets', 'satellite', 'hybrid'] as $key) {
            $this->assertStringContainsString(
                "key: '{$key}'",
                $script,
                "The base map switch should offer {$key}."
            );
        }
    }

    /**
     * The one that distinguishes hybrid from satellite: the reference
     * tiles are drawn for hybrid and for nothing else.
     */
    public function test_only_hybrid_draws_the_name_overlays(): void
    {
        $script = $this->mapJs();

        $this->assertStringContainsString("var wantsLabels = name === 'hybrid';", $script);

        // And the imagery itself is shared by both photograph views.
        $this->assertStringContainsString(
            "var onImagery = name === 'satellite' || name === 'hybrid';",
            $script
        );
    }

    /**
     * An older browser holding "satellite" from when that meant
     * "imagery with names" still gets a working map, and anything
     * unrecognised falls back to the street map rather than a blank one.
     */
    public function test_a_remembered_choice_is_honoured_and_anything_else_falls_back(): void
    {
        $script = $this->mapJs();

        $this->assertMatchesRegularExpression(
            "/remembered === 'satellite' \|\| remembered === 'hybrid'/",
            $script
        );

        $this->assertStringContainsString(": 'streets'", $script);
    }

    /**
     * EVERY TILE HOST THE MAP USES MUST BE IN THE POLICY.
     *
     * Hybrid draws from two providers at once — the imagery from one
     * host and the reference tiles from another — so this is where a
     * missing entry would show up as a photograph with no names on it
     * and no error anywhere.
     */
    public function test_every_tile_host_is_allowed_by_the_content_security_policy(): void
    {
        $map = config('visitdasol.map');
        $csp = $this->get(route('map'))->assertOk()->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp, 'The map page should carry a Content Security Policy.');

        $imgSrc = '';

        foreach (explode(';', $csp) as $directive) {
            if (str_starts_with(trim($directive), 'img-src')) {
                $imgSrc = trim($directive);
            }
        }

        $this->assertNotSame('', $imgSrc, 'The policy should name an img-src.');

        foreach (['tile_url', 'satellite_url', 'satellite_labels_url', 'satellite_roads_url'] as $key) {
            $url = $map[$key] ?? null;

            if (! $url) {
                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);

            $this->assertStringContainsString(
                $host,
                $imgSrc,
                "Tiles are loaded from {$host} ({$key}) but img-src does not allow it, "
                .'so the browser will refuse them silently.'
            );
        }
    }

    /* How sharply the tiles are drawn ------------------------------------ */

    /**
     * Retina tiles for the photograph, ordinary tiles for the lettering.
     *
     * detectRetina fetches the next zoom level in and draws it at half
     * size. On imagery that is the difference between a soft coastline
     * and a clear one. On anything with writing on it — the street map,
     * the two reference overlays — it also halves the size of every
     * label, so it is deliberately NOT used there.
     *
     * BOTH ZOOM CEILINGS HAVE TO MOVE WITH IT. Leaflet's own source
     * does zoomOffset++ and maxZoom-- when retina mode is on, so a
     * layer left at the plain numbers asks Esri for a level it never
     * published, and _setView blanks the layer outright at the closest
     * zoom. The screen that would show it is the one these corrections
     * never run on, which is exactly how it would ship unnoticed.
     */
    public function test_the_imagery_is_drawn_at_screen_density_and_the_lettering_is_not(): void
    {
        $script = $this->mapJs();

        $this->assertSame(
            1,
            substr_count($script, 'detectRetina: true'),
            'Exactly one layer — the imagery — should be drawn at retina density. '
            .'On a layer carrying labels it shrinks the lettering.'
        );

        // On the imagery layer, and nowhere else.
        $this->assertMatchesRegularExpression(
            '/satelliteUrl,\s*\{.*?detectRetina: true/s',
            $script,
            'detectRetina belongs on the imagery layer: it is the only one with no '
            .'lettering to shrink.'
        );

        // The two corrections, both conditional on the screen.
        $this->assertStringContainsString(
            'maxZoom: retinaTiles ? satelliteMax + 1 : satelliteMax',
            $script,
            "Without the raised ceiling, Leaflet's retina decrement puts the layer's "
            .'maxZoom below the map\'s and the imagery vanishes at the closest zoom.'
        );

        $this->assertStringContainsString(
            'maxNativeZoom: retinaTiles ? satelliteMax - 1 : satelliteMax',
            $script,
            'Without the lowered native cap, retina mode asks Esri for one zoom past '
            .'the end of the imagery and gets "no data" tiles back.'
        );
    }

    /** A map that reports distances should say what its own scale is. */
    public function test_the_map_carries_a_metric_scale_bar(): void
    {
        $script = $this->mapJs();

        $this->assertStringContainsString('L.control.scale(', $script);
        $this->assertStringContainsString('imperial: false', $script);
    }

    /**
     * The route is drawn with a casing under it.
     *
     * A single teal line is legible on the street map and very nearly
     * invisible over dark water on the imagery, which is where somebody
     * following a route is most likely to be looking.
     */
    public function test_the_route_is_drawn_with_a_casing_so_it_reads_over_imagery(): void
    {
        $script = $this->mapJs();

        $this->assertSame(
            2,
            substr_count($script, 'vd-route vd-route--casing'),
            'Both the plain route and the slope-coloured repaint need the casing; '
            .'without it the repaint loses the outline the first draw had.'
        );

        $this->assertStringContainsString(
            '.vd-route--casing',
            file_get_contents(public_path('css/visitdasol.css')),
            'The casing class is drawn by the script but styled by the stylesheet, '
            .'and nothing but a test connects the two.'
        );
    }

    /**
     * No secret reaches the page.
     *
     * The Mapbox URLs carry an access token. A public (pk.) token is
     * meant to be in a page, but a secret (sk.) one never is, and the
     * difference is one letter.
     *
     * NOTHING SERVES MAPBOX TILES ANY MORE — the street map comes from
     * OpenStreetMap and the imagery from Esri. The check is kept because
     * what it guards is not Mapbox: it is that a key from any provider
     * added later does not reach the browser by accident.
     */
    public function test_no_secret_mapbox_token_is_published(): void
    {
        $html = $this->get(route('map'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'sk.ey',
            $html,
            'A secret Mapbox token must never be sent to the browser.'
        );
    }
}
