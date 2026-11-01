<?php

namespace Tests\Feature\Map;

use App\Models\Resort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding yourself, and putting the route away again.
 *
 * The map could already locate a visitor: it takes several readings,
 * keeps the sharpest, draws a pin with an accuracy ring and lets them
 * drag it if the browser placed them in the wrong barangay. What it
 * could not do was keep up with somebody who was moving, or clear a
 * route once it was drawn.
 *
 * THE RULE THAT MATTERS MOST HERE IS THE GPS BEING LET GO OF. A watch
 * left running keeps the receiver warm and drains a phone, which is a
 * real cost to a tourist halfway round the bay. It is started only
 * when asked and stopped on the second press, when the route is
 * cleared, and when the page goes away.
 *
 * There is no browser in this suite, so nothing here moves a pin.
 * These hold the contract the browser code depends on.
 */
class GpsNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function mapJs(): string
    {
        return file_get_contents(public_path('js/map.js'));
    }

    private function mapHtml(): string
    {
        Resort::factory()->active()->create();

        return $this->get(route('map'))->assertOk()->getContent();
    }

    /* The controls reach the page ------------------------------------------ */

    public function test_the_map_offers_locate_follow_and_clear_route(): void
    {
        $html = $this->mapHtml();

        foreach (['data-map-locate', 'data-map-follow', 'data-map-clear-route'] as $hook) {
            $this->assertStringContainsString($hook, $html, "The map is missing {$hook}.");
        }
    }

    /**
     * Both new controls start hidden.
     *
     * Following is offered only where the browser can do it, and the
     * clear button means nothing until there is a line to clear.
     */
    public function test_the_new_controls_start_hidden(): void
    {
        $html = $this->mapHtml();

        $this->assertMatchesRegularExpression('/data-map-follow[^>]*hidden/', $html);
        $this->assertMatchesRegularExpression('/data-map-clear-route[^>]*hidden/', $html);
    }

    /** Following is a toggle, and says so to a screen reader. */
    public function test_following_is_announced_as_a_toggle(): void
    {
        $this->assertMatchesRegularExpression(
            '/data-map-follow[^>]*aria-pressed="false"/',
            $this->mapHtml()
        );
    }

    /* Letting go of the receiver -------------------------------------------- */

    /**
     * THE ONE THAT PROTECTS SOMEBODY'S BATTERY.
     *
     * Every way out of following has to reach clearWatch: pressing the
     * button again, clearing the route, and leaving the page.
     */
    public function test_every_way_out_of_following_releases_the_gps(): void
    {
        $script = $this->mapJs();

        $this->assertStringContainsString('function stopFollowing()', $script);
        $this->assertStringContainsString('navigator.geolocation.clearWatch(followWatch)', $script);
        $this->assertStringContainsString('followWatch = null', $script);

        // Clearing the route stops following too: somebody who has put
        // the route away is no longer navigating.
        $this->assertMatchesRegularExpression(
            '/data-map-clear-route.*?addEventListener.*?stopFollowing\(\)/s',
            $script
        );

        // And the page going away must not leave it running.
        $this->assertStringContainsString("addEventListener('pagehide', stopFollowing)", $script);
    }

    /** A refusal stops the watch rather than retrying forever. */
    public function test_a_refused_permission_stops_following(): void
    {
        $script = $this->mapJs();

        $this->assertMatchesRegularExpression(
            '/watchPosition\(.*?function \(error\).*?stopFollowing\(\)/s',
            $script
        );

        $this->assertStringContainsString('PERMISSION_DENIED', $script);
    }

    /**
     * Never without being asked.
     *
     * Both the one-off fix and the watch hang off a click. A page that
     * asks for GPS on load trains people to refuse the prompt.
     */
    public function test_the_gps_is_only_ever_touched_after_a_click(): void
    {
        $script = $this->mapJs();

        // Neither call may appear at the top level of a DOMContentLoaded
        // handler; both live inside a click listener.
        $this->assertMatchesRegularExpression(
            '/addEventListener\(.click.*?watchPosition/s',
            $script
        );

        $this->assertStringNotContainsString(
            "DOMContentLoaded', function () {\n    navigator.geolocation",
            $script
        );
    }

    /* Clearing the route ----------------------------------------------------- */

    public function test_clearing_a_route_takes_the_line_and_its_climb_panel_away(): void
    {
        $script = $this->mapJs();

        $this->assertStringContainsString('function clearRoute()', $script);

        $this->assertMatchesRegularExpression(
            '/function clearRoute\(\).*?map\.removeLayer\(routeLine\).*?routeLine = null/s',
            $script
        );

        // The climb panel describes THIS route. Left open beside no
        // line it is a figure about nothing.
        $this->assertMatchesRegularExpression(
            '/function clearRoute\(\).*?profileBox\.hidden = true/s',
            $script
        );
    }

    /** And the button only appears once there is something to clear. */
    public function test_the_clear_button_appears_with_the_route(): void
    {
        $script = $this->mapJs();

        $this->assertStringContainsString('clearRouteButton.hidden = false', $script);
        $this->assertStringContainsString('clearRouteButton.hidden = true', $script);
    }

    /* What must not have been disturbed -------------------------------------- */

    /**
     * The locate button's own behaviour is untouched: several readings,
     * the sharpest kept, a ring for the accuracy and a pin that can be
     * dragged when the browser is wrong.
     */
    public function test_the_existing_locating_still_works_the_way_it_did(): void
    {
        $script = $this->mapJs();

        $this->assertStringContainsString('function locate(button)', $script);
        $this->assertStringContainsString('function placeMe(', $script);

        // The accuracy ring.
        $this->assertStringContainsString('radius: accuracy', $script);

        // Correcting a bad fix by hand, and remembering it.
        $this->assertStringContainsString("hereMarker.on('dragend'", $script);

        // Road distances, not straight lines, once a position is known.
        $this->assertStringContainsString('loadRoadDistances()', $script);
    }

    /** Routing is still road routing through OSRM, not a drawn line. */
    public function test_routing_still_asks_the_road_router(): void
    {
        $script = $this->mapJs();

        $this->assertStringContainsString('function routeBetween(', $script);
        $this->assertStringContainsString('geometries=geojson', $script);

        $this->assertStringContainsString(
            'router.project-osrm.org',
            (string) config('visitdasol.map.routing_url')
        );
    }
}
