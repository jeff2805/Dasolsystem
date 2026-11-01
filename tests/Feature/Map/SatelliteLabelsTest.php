<?php

namespace Tests\Feature\Map;

use App\Models\Resort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Names on the satellite view.
 *
 * Aerial photography carries no writing. The imagery showed the
 * coastline beautifully and could not tell anybody which road they were
 * looking at, so two transparent reference layers are drawn over it:
 * one with place and locality names, one with roads and theirs.
 *
 * The trap this guards against is the Content Security Policy. Tile
 * hosts are read from config and written into img-src by
 * SecurityHeaders; a layer added to the map but not to the config list
 * is refused by the browser with nothing in the page to show for it.
 */
class SatelliteLabelsTest extends TestCase
{
    use RefreshDatabase;

    private function mapJs(): string
    {
        return file_get_contents(public_path('js/map.js'));
    }

    /* Configuration ------------------------------------------------------- */

    public function test_the_imagery_has_label_overlays_configured(): void
    {
        $map = config('visitdasol.map');

        $this->assertNotNull($map['satellite_labels_url']);
        $this->assertNotNull($map['satellite_roads_url']);

        foreach (['satellite_labels_url', 'satellite_roads_url'] as $key) {
            $this->assertStringContainsString('{z}', $map[$key]);
            $this->assertStringContainsString('{x}', $map[$key]);
            $this->assertStringContainsString('{y}', $map[$key]);
            $this->assertStringStartsWith('https://', $map[$key]);
        }
    }

    /**
     * The imagery is configured, credited, and separate from the names.
     *
     * THIS USED TO NAME ONE VENDOR. It asserted Esri's World Imagery
     * URL character for character, so the day the office moved the
     * imagery to Mapbox the test failed for a change that was entirely
     * intentional — it was reporting a decision, not a defect.
     *
     * What actually has to hold is below, and holds whoever supplies
     * the tiles:
     *
     *   - there IS an imagery layer, or the Satellite and Hybrid
     *     buttons have nothing behind them;
     *   - it is a real tile URL, so Leaflet can fill in the numbers;
     *   - it is credited, because every provider's licence requires it;
     *   - the name overlays come from somewhere else. They have to be
     *     published as TRANSPARENT reference tiles. An ordinary street
     *     layer is opaque and would simply hide the photograph, which
     *     is the street map with extra steps.
     */
    public function test_the_imagery_is_configured_credited_and_separate_from_the_names(): void
    {
        $map = config('visitdasol.map');

        $this->assertNotNull($map['satellite_url'], 'Satellite and Hybrid need an imagery layer.');
        $this->assertStringStartsWith('https://', $map['satellite_url']);

        foreach (['{z}', '{x}', '{y}'] as $placeholder) {
            $this->assertStringContainsString($placeholder, $map['satellite_url']);
        }

        $this->assertNotEmpty(
            trim(strip_tags((string) $map['satellite_attribution'])),
            'Every tile provider requires attribution on the map.'
        );

        $imagery = parse_url($map['satellite_url'], PHP_URL_HOST);

        foreach (['satellite_labels_url', 'satellite_roads_url'] as $key) {
            if (! $map[$key]) {
                continue;
            }

            $this->assertNotSame(
                $imagery,
                parse_url($map[$key], PHP_URL_HOST),
                "The {$key} overlay comes from the same host as the imagery. "
                .'The overlays must be transparent reference tiles from a '
                .'provider that publishes them as such.'
            );
        }
    }

    /* The policy that would silently break them --------------------------- */

    public function test_the_security_policy_allows_the_label_tiles(): void
    {
        $csp = $this->get(route('map'))->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);

        $host = parse_url(config('visitdasol.map.satellite_labels_url'), PHP_URL_HOST);

        $this->assertStringContainsString($host, $csp,
            'The label tile host is missing from img-src, so the browser will refuse the tiles.');

        // And the images are still only allowed as images.
        $this->assertStringContainsString('img-src', $csp);
    }

    /**
     * Named in the policy from config, so moving the imagery to another
     * provider cannot leave the label hosts behind.
     */
    public function test_the_policy_is_built_from_the_config_not_hard_coded(): void
    {
        config(['visitdasol.map.satellite_labels_url' => 'https://labels.example.org/{z}/{y}/{x}.png']);

        $csp = $this->get(route('map'))->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('labels.example.org', $csp);
    }

    /* What reaches the page ------------------------------------------------ */

    public function test_the_map_element_carries_both_overlays(): void
    {
        Resort::factory()->active()->create();

        $map = config('visitdasol.map');

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('data-satellite-labels-url="'.e($map['satellite_labels_url']).'"', false)
            ->assertSee('data-satellite-roads-url="'.e($map['satellite_roads_url']).'"', false);
    }

    /**
     * Dropping an overlay in config must drop it from the page, not
     * render an empty attribute the script then treats as a URL.
     */
    public function test_an_overlay_switched_off_is_not_sent(): void
    {
        Resort::factory()->active()->create();

        config(['visitdasol.map.satellite_roads_url' => null]);

        $this->get(route('map'))
            ->assertOk()
            ->assertDontSee('data-satellite-roads-url', false)
            ->assertSee('data-satellite-labels-url', false);
    }

    /* The rules the script must keep --------------------------------------- */

    /**
     * Above the imagery, below every marker. A name drawn over a resort
     * pin is a name sitting on the thing somebody is trying to tap.
     */
    public function test_labels_sit_in_their_own_pane_between_tiles_and_markers(): void
    {
        $source = $this->mapJs();

        $this->assertStringContainsString("map.createPane('vd-labels')", $source);
        $this->assertStringContainsString("zIndex = 350", $source);

        // Leaflet's own panes: tiles 200, overlays 400, markers 600.
        $this->assertStringContainsString("pane: 'vd-labels'", $source);

        // And nothing on a label tile may swallow a tap.
        $this->assertStringContainsString("pointerEvents = 'none'", $source);
    }

    /**
     * The street map already has its names drawn in. Laying these over
     * it would print every name twice, slightly offset.
     *
     * NARROWED, DELIBERATELY. This used to read "labels travel with the
     * imagery", and the condition was wantsSatellite. There are three
     * base maps now and the photograph comes two ways: Satellite is the
     * imagery alone, Hybrid is the imagery with these names on it. So
     * the rule is no longer "imagery" but "hybrid" — which still keeps
     * them off the street map, which is what this test is really for.
     */
    public function test_the_labels_are_drawn_for_hybrid_and_for_nothing_else(): void
    {
        $source = $this->mapJs();

        $this->assertMatchesRegularExpression(
            '/labelLayers\.forEach.*?wantsLabels && !map\.hasLayer\(layer\).*?layer\.addTo\(map\)/s',
            $source,
            'Label overlays must be added only for the hybrid base map.'
        );

        $this->assertMatchesRegularExpression(
            '/labelLayers\.forEach.*?!wantsLabels && map\.hasLayer\(layer\).*?map\.removeLayer\(layer\)/s',
            $source,
            'Label overlays must be removed again for every other base map.'
        );

        // And wantsLabels is hybrid alone, not "any photograph".
        $this->assertStringContainsString("var wantsLabels = name === 'hybrid';", $source);
    }

    /* Decluttering without duplicating ------------------------------------- */

    /**
     * The overlap is fixed by drawing the secondary pins smaller when
     * the map is far out, NOT by adding and removing layers — that
     * would fight the layers control and could leave two of a marker.
     */
    public function test_crowding_is_eased_by_zoom_bands_not_by_new_markers(): void
    {
        $source = $this->mapJs();

        $this->assertStringContainsString("element.classList.toggle('is-zoom-far'", $source);
        $this->assertStringContainsString("element.classList.toggle('is-zoom-near'", $source);
        $this->assertStringContainsString("map.on('zoomend', showZoomBand)", $source);

        // One layer group per kind, created once, as before.
        $this->assertSame(1, substr_count($source, 'var fuelLayer = L.layerGroup()'));

        // The fee checkpoint layer was the other one. It went with the
        // environmental fee module, and nothing in map.js should build
        // a layer for it any more.
        $this->assertSame(0, substr_count($source, 'checkpointLayer'));
    }

    public function test_the_stylesheet_carries_the_zoom_bands_and_the_satellite_halo(): void
    {
        $css = file_get_contents(public_path('css/visitdasol.css'));

        foreach ([
            '.vd-map.is-zoom-far .vd-brgy',
            '.vd-map.is-zoom-near .vd-brgy',
            '.vd-map.is-satellite .vd-brgy',
            '.vd-map.is-satellite .leaflet-tooltip.vd-brgy-label',
            '.vd-map.is-zoom-far .vd-pin--fuel',
        ] as $rule) {
            $this->assertStringContainsString($rule, $css, "Missing CSS rule: {$rule}");
        }
    }

    public function test_the_satellite_class_follows_the_basemap_switch(): void
    {
        $this->assertStringContainsString(
            "element.classList.toggle('is-satellite', wantsSatellite)",
            $this->mapJs()
        );
    }

    /* Everything that was already there ------------------------------------ */

    public function test_the_existing_layers_and_markers_still_reach_the_page(): void
    {
        Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);

        $response = $this->get(route('map'))->assertOk();

        $response->assertSee('Salt Breeze Resort')
            ->assertSee('data-barangays', false)
            ->assertSee('data-points', false)
            ->assertSee('Barangay names')
            ->assertSee('Satellite')
            ->assertSee('Road distance');
    }
}
