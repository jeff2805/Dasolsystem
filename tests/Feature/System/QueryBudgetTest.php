<?php

namespace Tests\Feature\System;

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Booking;
use App\Models\Destination;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pages must not get slower as data piles up.
 *
 * Every list page is loaded twice: once with a little data, once with much
 * more. The number of database queries must be the same both times. A page
 * that runs one extra query per row (the "N+1" problem) would be fine in a
 * demo and slow to a crawl after a busy season; this test catches it.
 *
 * Phase 20 found two such pages (owner rooms and owner availability) and
 * fixed them; this test keeps them fixed.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    private User $owner;

    private User $tourist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2029-12-01 09:00:00');

        $this->mto = User::factory()->superAdmin()->create();
        $this->owner = User::factory()->resortOwner()->create();
        Resort::factory()->for($this->owner, 'owner')->active()->create();
        $this->tourist = User::factory()->create();
    }

    /** Adds $count of everything: resorts, rooms, bookings, content, log entries. */
    private function addData(int $count): void
    {
        $resort = $this->owner->resort;

        for ($i = 0; $i < $count; $i++) {
            $month = str_pad((string) (($i % 8) + 1), 2, '0', STR_PAD_LEFT);

            $other = Room::factory()->for(Resort::factory()->active())->create();
            Booking::factory()->for($other)->for($this->tourist, 'tourist')->confirmed()->create();

            $room = Room::factory()->for($resort)->create();
            Booking::factory()->for($room)->forStay("2030-{$month}-10", "2030-{$month}-12")->confirmed()->create();

            Destination::factory()->create();
            Announcement::factory()->published()->create();
            ActivityLog::create(['user_id' => $this->owner->id, 'action' => 'resort.updated', 'description' => 'Test entry']);
        }
    }

    /**
     * @return array<string, array{0: ?User, 1: string}>
     */
    private function pages(): array
    {
        return [
            'home' => [null, route('home')],
            'resorts' => [null, route('resorts.index')],
            // The same listing with dates, which also works out what is free.
            // Budgeted separately because it is the path that could run one
            // availability query per card.
            'resorts with dates' => [null, route('resorts.index', ['check_in' => '2030-03-10', 'check_out' => '2030-03-12'])],
            'destinations' => [null, route('destinations.index')],
            'announcements' => [null, route('announcements.index')],
            'map' => [null, route('map')],
            // The map's occupancy reading, which sweeps every resort's
            // reservations. Two queries whatever the number of pins, or a
            // busy season turns the map into one request per resort.
            'map occupancy' => [null, route('map.occupancy', [
                'check_in' => '2030-03-10', 'check_out' => '2030-03-12',
            ])],
            'office dashboard' => [$this->mto, route('admin.dashboard')],
            'office resorts' => [$this->mto, route('admin.resorts.index')],
            'office users' => [$this->mto, route('admin.users.index')],
            'office bookings' => [$this->mto, route('admin.bookings.index')],
            'office destinations' => [$this->mto, route('admin.destinations.index')],
            'office announcements' => [$this->mto, route('admin.announcements.index')],
            'office activity log' => [$this->mto, route('admin.activity-logs.index')],
            'office reports' => [$this->mto, route('admin.reports.index', ['from' => '2029-06-01', 'to' => '2030-12-31'])],
            'office map' => [$this->mto, route('admin.map')],
            'owner dashboard' => [$this->owner, route('owner.dashboard')],
            'owner rooms' => [$this->owner, route('owner.rooms.index')],
            'owner reservations' => [$this->owner, route('owner.bookings.index')],
            'owner availability' => [$this->owner, route('owner.availability', ['month' => '2030-03'])],
            'owner statistics' => [$this->owner, route('owner.statistics')],
            'tourist dashboard' => [$this->tourist, route('tourist.dashboard')],
            'tourist reservations' => [$this->tourist, route('tourist.bookings.index')],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function measure(): array
    {
        $counts = [];

        foreach ($this->pages() as $label => [$user, $url]) {
            /*
             * A page listed with no user is a page measured SIGNED OUT.
             *
             * This used only to log in and never out, so once any page
             * had authenticated, every later null-user page inherited
             * that session — and the second pass began still signed in
             * as the last page's user. "home" was therefore counted as
             * a guest the first time and as a tourist the second, and
             * the two numbers were never comparable.
             *
             * It stayed hidden while nothing on the public pages cost a
             * query per signed-in visitor. The header's unread message
             * count does, so the leak started reading as an N+1 that
             * was not there.
             */
            if ($user) {
                $this->actingAs($user);
            } else {
                auth()->logout();
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url)->assertOk();
            $counts[$label] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $counts;
    }

    public function test_no_page_runs_more_queries_as_the_data_grows(): void
    {
        $this->addData(2);
        $small = $this->measure();

        $this->addData(12);
        $large = $this->measure();

        foreach ($small as $page => $count) {
            $this->assertLessThanOrEqual(
                $count,
                $large[$page],
                "The {$page} page ran {$count} queries with a little data but {$large[$page]} with more. "
                .'Something is being loaded one row at a time; eager load it or query it in one go.'
            );
        }
    }

    public function test_no_page_is_extravagant(): void
    {
        $this->addData(3);

        foreach ($this->measure() as $page => $count) {
            $this->assertLessThanOrEqual(40, $count, "The {$page} page runs {$count} queries.");
        }
    }
}
