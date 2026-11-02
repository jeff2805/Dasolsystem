<?php

namespace Tests\Feature\Map;

use App\Enums\BookingStatus;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Capacity and occupancy on the public map.
 *
 * The figures must agree with the booking engine exactly: the same
 * blocking() statuses, the same overlapping() dates, and the same
 * night-by-night peak. A map that says a resort is free when the booking
 * form will refuse the stay is worse than no map at all.
 */
class ResortOccupancyTest extends TestCase
{
    use RefreshDatabase;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-06-01 09:00:00');

        $this->resort = Resort::factory()->active()->create([
            'name' => 'Salt Breeze Resort',
            'latitude' => 15.99,
            'longitude' => 119.88,
        ]);
    }

    private function room(int $capacity, int $units, array $attributes = []): Room
    {
        return Room::factory()->for($this->resort)->create($attributes + [
            'capacity' => $capacity,
            'total_rooms' => $units,
        ]);
    }

    private function stay(
        Room $room,
        string $in,
        string $out,
        int $guests = 2,
        int $rooms = 1,
        BookingStatus $status = BookingStatus::Confirmed,
    ): Booking {
        return Booking::factory()
            ->for($room)
            ->for($this->resort)
            ->for(User::factory(), 'tourist')
            ->forStay($in, $out, $rooms)
            ->withStatus($status)
            ->create(['guests' => $guests, 'adults' => $guests, 'children' => 0]);
    }

    private function figures(string $in = '2030-07-10', string $out = '2030-07-12'): array
    {
        return app(AvailabilityService::class)->occupancyForResorts(
            collect([$this->resort]),
            $in,
            $out
        )[$this->resort->id];
    }

    /* Capacity ------------------------------------------------------------- */

    public function test_capacity_adds_up_every_unit_of_every_room_type(): void
    {
        $this->room(capacity: 4, units: 3);   // 12
        $this->room(capacity: 2, units: 5);   // 10

        $figures = $this->figures();

        $this->assertSame(22, $figures['capacity']);
        $this->assertSame(8, $figures['rooms_total']);
        $this->assertSame(8, $figures['rooms_free']);
        $this->assertSame(0, $figures['guests']);
        $this->assertSame(22, $figures['remaining']);
        $this->assertSame(0, $figures['percent']);
        $this->assertSame('available', $figures['status']);
    }

    public function test_a_room_switched_off_is_not_capacity(): void
    {
        $this->room(capacity: 4, units: 2);
        $this->room(capacity: 10, units: 5, attributes: ['status' => RoomStatus::Inactive]);

        // Only the active room type counts: the other cannot be booked.
        $this->assertSame(8, $this->figures()['capacity']);
    }

    public function test_a_resort_with_no_rooms_is_unknown_rather_than_empty(): void
    {
        $figures = $this->figures();

        $this->assertSame(0, $figures['capacity']);
        $this->assertSame('unknown', $figures['status']);
    }

    /* Occupancy ------------------------------------------------------------ */

    public function test_guests_and_rooms_held_are_both_counted(): void
    {
        $room = $this->room(capacity: 4, units: 4);          // 16 guest capacity
        $this->stay($room, '2030-07-10', '2030-07-12', guests: 6, rooms: 2);

        $figures = $this->figures();

        $this->assertSame(16, $figures['capacity']);
        $this->assertSame(6, $figures['guests']);
        $this->assertSame(10, $figures['remaining']);
        $this->assertSame(2, $figures['rooms_free']);
        $this->assertSame(38, $figures['percent']);   // 6/16
        $this->assertSame('available', $figures['status']);
    }

    /**
     * The whole reason the engine sweeps night by night. Two stays that
     * overlap the asked-for range but not each other must not be added
     * together, or a resort with one room would look doubly booked.
     */
    public function test_stays_in_relay_are_not_added_together(): void
    {
        $room = $this->room(capacity: 4, units: 1);

        $this->stay($room, '2030-07-10', '2030-07-12', guests: 3);
        $this->stay($room, '2030-07-12', '2030-07-14', guests: 4);

        $figures = $this->figures('2030-07-10', '2030-07-14');

        // The busiest single night is 4 guests in 1 unit, not 7 in 2.
        $this->assertSame(4, $figures['guests']);
        $this->assertSame(0, $figures['rooms_free']);
        $this->assertSame('full', $figures['status']);
    }

    public function test_cancelled_and_rejected_stays_hold_nothing(): void
    {
        $room = $this->room(capacity: 4, units: 1);

        $this->stay($room, '2030-07-10', '2030-07-12', guests: 4, status: BookingStatus::Cancelled);
        $this->stay($room, '2030-07-10', '2030-07-12', guests: 4, status: BookingStatus::Rejected);

        $figures = $this->figures();

        $this->assertSame(0, $figures['guests']);
        $this->assertSame(1, $figures['rooms_free']);
        $this->assertSame('available', $figures['status']);
    }

    public function test_a_waiting_request_still_holds_the_room(): void
    {
        $room = $this->room(capacity: 4, units: 1);
        $this->stay($room, '2030-07-10', '2030-07-12', guests: 4, status: BookingStatus::Pending);

        // Held while the owner decides, exactly as the booking engine holds it.
        $this->assertSame(0, $this->figures()['rooms_free']);
    }

    public function test_a_stay_outside_the_asked_for_nights_is_not_counted(): void
    {
        $room = $this->room(capacity: 4, units: 1);
        $this->stay($room, '2030-08-01', '2030-08-03', guests: 4);

        $this->assertSame(0, $this->figures('2030-07-10', '2030-07-12')['guests']);
    }

    public function test_a_guest_may_arrive_on_the_day_another_leaves(): void
    {
        $room = $this->room(capacity: 4, units: 1);
        $this->stay($room, '2030-07-08', '2030-07-10', guests: 4);

        // The departing stay ends the morning the range begins.
        $this->assertSame(1, $this->figures('2030-07-10', '2030-07-12')['rooms_free']);
    }

    /* Full, and the colour that goes with it -------------------------------- */

    public function test_a_resort_with_no_free_units_is_full(): void
    {
        $room = $this->room(capacity: 10, units: 1);
        $this->stay($room, '2030-07-10', '2030-07-12', guests: 1, rooms: 1);

        $figures = $this->figures();

        // One guest in the only room still leaves nothing to book.
        $this->assertSame(0, $figures['rooms_free']);
        $this->assertSame('full', $figures['status']);
    }

    public function test_seventy_percent_full_reads_as_filling_up(): void
    {
        $room = $this->room(capacity: 10, units: 2);          // 20 capacity
        $this->stay($room, '2030-07-10', '2030-07-12', guests: 14, rooms: 1);

        $figures = $this->figures();

        $this->assertSame(70, $figures['percent']);
        $this->assertSame('busy', $figures['status']);
    }

    public function test_occupancy_never_goes_negative(): void
    {
        $room = $this->room(capacity: 4, units: 2);           // 8 capacity
        $this->stay($room, '2030-07-10', '2030-07-12', guests: 8, rooms: 1);

        // The owner cuts the room right down afterwards.
        $room->forceFill(['capacity' => 1])->save();

        $figures = $this->figures();

        $this->assertSame(2, $figures['capacity']);
        $this->assertSame(0, $figures['remaining']);
        $this->assertSame(100, $figures['percent']);
        $this->assertSame('full', $figures['status']);
    }

    public function test_a_backwards_range_reports_nothing_rather_than_guessing(): void
    {
        $this->room(capacity: 4, units: 2);

        $this->assertSame([], app(AvailabilityService::class)->occupancyForResorts(
            collect([$this->resort]), '2030-07-12', '2030-07-10'
        ));
    }

    /* The map page ---------------------------------------------------------- */

    public function test_the_map_carries_each_resorts_figures(): void
    {
        $room = $this->room(capacity: 4, units: 2);
        $today = CarbonImmutable::today();

        $this->stay($room, $today->toDateString(), $today->addDays(2)->toDateString(), guests: 3);

        $point = collect($this->get(route('map'))->assertOk()->viewData('resorts'))
            ->firstWhere('id', 'resort-'.$this->resort->id);

        $this->assertNotNull($point['occupancy']);
        $this->assertSame(8, $point['occupancy']['capacity']);
        $this->assertSame(3, $point['occupancy']['guests']);
        $this->assertSame('available', $point['occupancy']['status']);
        $this->assertStringContainsString('#rooms-title', $point['book_url']);
    }

    /**
     * The map shows tonight, and says what the colours mean.
     *
     * IT USED TO OFFER DATES TOO. The check-in and check-out pickers
     * were removed from this page on request, so the assertions that
     * they are present have gone with them — a test demanding a
     * control the design no longer has is reporting a decision, not a
     * defect.
     *
     * What still has to hold is the part that matters: the pins are
     * coloured for tonight, those colours are explained in words as
     * well as in colour, and the endpoint that repaints them is still
     * on the page.
     */
    public function test_the_map_page_shows_tonight_and_explains_the_colours(): void
    {
        $this->room(capacity: 4, units: 2);

        $this->get(route('map'))
            ->assertOk()
            // Where the pins go to be recoloured.
            ->assertSee(route('map.occupancy'), false)
            // Colour is never the only signal, so the key names all three.
            ->assertSee('Rooms free')
            ->assertSee('Filling up')
            ->assertSee('Fully booked');
    }

    /** And the pickers really are gone from this page. */
    public function test_the_map_page_no_longer_asks_for_dates(): void
    {
        $this->room(capacity: 4, units: 2);

        $this->get(route('map'))
            ->assertOk()
            ->assertDontSee('data-map-dates', false)
            ->assertDontSee('name="check_in"', false)
            ->assertDontSee('name="check_out"', false)
            ->assertDontSee('Check these nights');
    }

    /**
     * The office map is for checking where pins sit. Colouring them by how
     * busy a resort is would say nothing about that, so it keeps the plain
     * markers and never calls the occupancy endpoint.
     */
    public function test_the_office_map_is_left_alone(): void
    {
        $this->room(capacity: 4, units: 2);

        $mto = User::factory()->superAdmin()->create();

        $this->actingAs($mto)->get(route('admin.map'))
            ->assertOk()
            ->assertDontSee('data-occupancy-url', false)
            ->assertDontSee('data-map-dates', false);
    }

    public function test_the_map_still_shows_a_resort_that_has_published_no_rooms(): void
    {
        $point = collect($this->get(route('map'))->assertOk()->viewData('resorts'))
            ->firstWhere('id', 'resort-'.$this->resort->id);

        $this->assertSame('unknown', $point['occupancy']['status']);
    }

    /**
     * Coordinates are NOT NULL on the resorts table, so a listing without
     * a place on the map cannot be stored in the first place. The map's
     * whereNotNull guard is belt and braces; what matters to a visitor is
     * that every pin drawn has real figures behind it.
     */
    public function test_every_resort_on_the_map_has_a_place_and_a_reading(): void
    {
        $this->room(capacity: 4, units: 2);

        Resort::factory()->active()->create(['name' => 'Second Resort']);

        $drawn = collect($this->get(route('map'))->assertOk()->viewData('resorts'));

        $this->assertCount(2, $drawn);

        foreach ($drawn as $point) {
            $this->assertNotNull($point['lat']);
            $this->assertNotNull($point['lng']);
            $this->assertArrayHasKey('status', $point['occupancy']);
        }
    }

    public function test_a_resort_outside_this_municipality_is_left_off_the_map(): void
    {
        Resort::factory()->active()->create(['name' => 'Away Resort'])
            ->forceFill(['municipality' => 'Alaminos'])->save();

        $names = collect($this->get(route('map'))->viewData('resorts'))->pluck('name');

        $this->assertContains('Salt Breeze Resort', $names);
        $this->assertNotContains('Away Resort', $names);
    }

    /* The JSON endpoint ------------------------------------------------------ */

    public function test_the_endpoint_answers_for_the_nights_asked_about(): void
    {
        $room = $this->room(capacity: 4, units: 2);
        $this->stay($room, '2030-07-10', '2030-07-12', guests: 7, rooms: 2);

        $response = $this->getJson(route('map.occupancy', [
            'check_in' => '2030-07-10',
            'check_out' => '2030-07-12',
        ]))->assertOk();

        $response->assertJsonPath('projected', true);
        $response->assertJsonPath('nights', 2);
        $response->assertJsonPath('resorts.0.id', 'resort-'.$this->resort->id);
        $response->assertJsonPath('resorts.0.guests', 7);
        $response->assertJsonPath('resorts.0.rooms_free', 0);
        $response->assertJsonPath('resorts.0.status', 'full');
    }

    public function test_the_endpoint_defaults_to_tonight(): void
    {
        $this->room(capacity: 4, units: 2);

        $this->getJson(route('map.occupancy'))
            ->assertOk()
            ->assertJsonPath('projected', false)
            ->assertJsonPath('nights', 1)
            ->assertJsonPath('check_in', CarbonImmutable::today()->toDateString());
    }

    public function test_the_endpoint_refuses_dates_that_make_no_sense(): void
    {
        $this->getJson(route('map.occupancy', [
            'check_in' => '2030-07-12',
            'check_out' => '2030-07-10',
        ]))->assertStatus(422)->assertJsonValidationErrors('check_out');

        $this->getJson(route('map.occupancy', [
            'check_in' => '2020-01-01',
            'check_out' => '2020-01-03',
        ]))->assertStatus(422)->assertJsonValidationErrors('check_in');

        $this->getJson(route('map.occupancy', ['check_in' => 'soon', 'check_out' => 'later']))
            ->assertStatus(422);
    }

    public function test_a_check_in_with_no_check_out_is_refused(): void
    {
        $this->getJson(route('map.occupancy', ['check_in' => '2030-07-10']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('check_out');
    }

    /* Privacy ----------------------------------------------------------------- */

    public function test_the_endpoint_never_names_a_guest_or_a_reservation(): void
    {
        $room = $this->room(capacity: 4, units: 2);

        $tourist = User::factory()->create([
            'first_name' => 'Bea',
            'last_name' => 'Santos',
            'email' => 'bea@example.test',
        ]);

        $booking = Booking::factory()
            ->for($room)->for($this->resort)->for($tourist, 'tourist')
            ->forStay('2030-07-10', '2030-07-12')
            ->confirmed()
            ->create(['contact_name' => 'Bea Santos', 'contact_phone' => '09171234567']);

        $body = $this->getJson(route('map.occupancy', [
            'check_in' => '2030-07-10',
            'check_out' => '2030-07-12',
        ]))->assertOk()->getContent();

        foreach ([
            'Bea', 'Santos', 'bea@example.test', '09171234567',
            $booking->booking_reference,
            (string) $room->price_per_night,
        ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $body,
                "The public map leaked {$secret}."
            );
        }
    }

    public function test_the_map_reports_only_on_resorts_the_public_may_see(): void
    {
        // The factory leaves a new listing Pending, which is what an
        // application that the office has not approved yet looks like.
        $hidden = Resort::factory()->create([
            'name' => 'Unapproved Resort',
            'latitude' => 15.98,
            'longitude' => 119.87,
        ]);

        Room::factory()->for($hidden)->create(['capacity' => 4, 'total_rooms' => 2]);

        $body = $this->getJson(route('map.occupancy'))->assertOk();

        $body->assertJsonMissing(['id' => 'resort-'.$hidden->id]);
        $this->assertStringNotContainsString('Unapproved Resort', $body->getContent());
    }

    /* Agreement with the booking engine ---------------------------------------- */

    /**
     * The map and the resort page are two readings of the same inventory.
     * If they ever disagree, one of them is sending a visitor somewhere
     * they cannot stay.
     */
    public function test_free_rooms_on_the_map_match_what_the_booking_engine_offers(): void
    {
        $availability = app(AvailabilityService::class);

        $small = $this->room(capacity: 2, units: 3);
        $large = $this->room(capacity: 6, units: 2);

        $this->stay($small, '2030-07-10', '2030-07-13', guests: 2, rooms: 2);
        $this->stay($large, '2030-07-11', '2030-07-12', guests: 5, rooms: 1);

        $perRoom = $availability->availableUnitsForRooms(
            [$small, $large], '2030-07-10', '2030-07-13'
        );

        $this->assertSame(
            array_sum($perRoom),
            $this->figures('2030-07-10', '2030-07-13')['rooms_free'],
            'The map and the booking engine disagree about how many rooms are free.'
        );
    }
}
