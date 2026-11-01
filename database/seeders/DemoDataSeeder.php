<?php

namespace Database\Seeders;

use App\Enums\AnnouncementStatus;
use App\Enums\BookingStatus;
use App\Enums\DestinationStatus;
use App\Enums\ResortStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Amenity;
use App\Models\Booking;
use App\Models\Announcement;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\OwnerProfile;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingPricer;
use App\Services\BookingReferenceGenerator;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * DEMO DATA: fictional records for development and demonstrations.
 *
 * Every name ends in "(Demo)", every description starts with "DEMO DATA.",
 * and every demo account uses the @demo.visitdasol.test email domain.
 * Nothing here describes a real resort, place, or event. Map pins are
 * approximate placeholders near the municipal center.
 *
 * The records cover each workflow state:
 *   owners:  active (x4), pending application, rejected application, inactive
 *   resorts: active (x3, one featured), pending, active-but-owner-inactive (hidden)
 *   rooms:   active and inactive room types
 *   destinations: active, featured, and one hidden
 *   announcements: published and draft
 *   bookings:     pending, confirmed, completed, and cancelled
 *
 * Future bookings are made through BookingService, so the demo data can
 * never contain a reservation the booking rules would have refused.
 */
class DemoDataSeeder extends Seeder
{
    private const DESCRIPTION_PREFIX = 'DEMO DATA. ';

    /**
     * Palettes for the demo cover art: [sky, water, land], each RGB.
     *
     * These are deliberately flat bands of colour rather than anything
     * resembling a photograph, so a demo listing can never be mistaken for a
     * picture of a real place. Replace them with the tourism office's own
     * photographs before this is shown as real content.
     *
     * @var array<string, array{0: list<int>, 1: list<int>, 2: list<int>}>
     */
    private const COVERS = [
        'beach' => [[198, 232, 236], [26, 146, 152], [13, 59, 71]],
        'cove' => [[212, 228, 214], [31, 124, 116], [18, 66, 60]],
        'mangrove' => [[214, 231, 210], [46, 107, 78], [22, 58, 44]],
        'coral' => [[253, 226, 196], [226, 138, 92], [93, 45, 40]],
        'dusk' => [[210, 205, 226], [86, 92, 140], [30, 32, 58]],
        'island' => [[196, 226, 240], [20, 118, 160], [12, 48, 68]],
        'salt' => [[240, 238, 226], [206, 200, 178], [116, 110, 92]],
        'plaza' => [[252, 236, 205], [242, 177, 52], [104, 72, 16]],
        'hills' => [[214, 232, 226], [98, 154, 120], [34, 74, 58]],
        'falls' => [[206, 230, 226], [58, 140, 128], [20, 62, 56]],
    ];

    private ?User $mto = null;

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    private array $accounts = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data must never be seeded in production.');
        }

        $password = (string) config('visitdasol.demo.password');
        if (mb_strlen($password) < 8) {
            throw new RuntimeException('Set DEMO_ACCOUNT_PASSWORD (at least 8 characters) in .env, run "php artisan config:clear", and seed again.');
        }

        $domain = config('visitdasol.demo.email_domain');
        if (User::query()->where('email', 'like', '%@'.$domain)->exists()) {
            $this->command?->warn('Demo data already exists. To rebuild it, run: php artisan migrate:fresh --seed');

            return;
        }

        $this->mto = User::query()->withRole(UserRole::SuperAdmin)->oldest('id')->first();

        /*
         * Sample stays are made through the real BookingService, which refuses
         * a room whose resort the public cannot see — and a demo resort is
         * hidden whenever DEMO_LISTINGS_PUBLIC is off. Seeding has to work
         * either way, so the rule is lifted while the data is written and put
         * back afterwards, even if seeding throws. Nothing outside this method
         * ever sees the setting change.
         */
        $wasPublic = config('visitdasol.demo.listings_public');
        config(['visitdasol.demo.listings_public' => true]);

        try {
            DB::transaction(function () use ($password): void {
                $this->seedOwnersAndResorts($password);
                $this->seedTourists($password);
                $this->seedDestinations();
                $this->seedAnnouncements();
                $this->seedBookings();
            });
        } finally {
            config(['visitdasol.demo.listings_public' => $wasPublic]);
        }

        $this->command?->newLine();
        $this->command?->info('DEMO accounts (password: the value of DEMO_ACCOUNT_PASSWORD in .env)');
        $this->command?->table(['Role', 'Email', 'Status', 'What to test'], $this->accounts);
    }

    /* ------------------------------------------------------------------ */

    private function seedOwnersAndResorts(string $password): void
    {
        // 1. Active owner with an active, featured resort.
        $owner = $this->owner('owner.saltbreeze', 'Carmela', 'Reyes', UserStatus::Active, 'Salt Breeze Hospitality (Demo)', 'Owner', 'Active owner, featured active resort', $password);
        $resort = $this->resort($owner, [
            'name' => 'Salt Breeze Beach Resort (Demo)',
            'barangay' => 'Tambobong',
            'latitude' => 16.0080000,
            'longitude' => 119.8700000,
            'summary' => 'A fictional beachfront resort with rooms for couples and families.',
            'cover' => 'beach',
        ], ResortStatus::Active, featured: true);
        $this->amenities($resort, ['Beachfront', 'Wi-Fi', 'Parking', 'Restaurant', 'Cottages', 'Showers and changing rooms']);
        $this->room($resort, 'Standard Room', RoomType::Budget, 2, 1800, 5);
        $this->room($resort, 'Family Cottage', RoomType::Cottage, 6, 3500, 3);
        $this->room($resort, 'Seaview Villa', RoomType::Premium, 4, 6500, 2);

        // 2. Active owner with an active resort.
        $owner = $this->owner('owner.bayside', 'Ramon', 'Villanueva', UserStatus::Active, 'Bayside Kubo Ventures (Demo)', 'Manager', 'Active owner, active resort', $password);
        $resort = $this->resort($owner, [
            'name' => 'Bayside Kubo Retreat (Demo)',
            'barangay' => 'Uli',
            'latitude' => 15.9985000,
            'longitude' => 119.8745000,
            'summary' => 'A fictional retreat with native-style cottages.',
            'cover' => 'cove',
        ], ResortStatus::Active);
        $this->amenities($resort, ['Beachfront', 'Cottages', 'Grilling area', 'Videoke', 'Kayak rental']);
        $this->room($resort, 'Nipa Cottage', RoomType::Cottage, 4, 1500, 6);
        $this->room($resort, 'Deluxe Room', RoomType::Premium, 2, 2800, 4);

        // 3. Active owner with an active resort and one inactive room type.
        $owner = $this->owner('owner.mangrove', 'Liza', 'Fernandez', UserStatus::Active, 'Mangrove View Inn Co. (Demo)', 'Owner', 'Active owner, has an inactive room type', $password);
        $resort = $this->resort($owner, [
            'name' => 'Mangrove View Inn (Demo)',
            'barangay' => 'San Vicente',
            'latitude' => 15.9800000,
            'longitude' => 119.8760000,
            'summary' => 'A fictional inn with standard and family rooms.',
            'cover' => 'mangrove',
        ], ResortStatus::Active);
        $this->amenities($resort, ['Air-conditioned rooms', 'Wi-Fi', 'Parking', 'Swimming pool', 'Wheelchair accessible']);
        $this->room($resort, 'Standard Room', RoomType::Budget, 2, 1500, 4);
        $this->room($resort, 'Family Room', RoomType::Cottage, 5, 3200, 2);
        $this->room($resort, 'Loft Room', RoomType::Budget, 3, 2600, 1, RoomStatus::Inactive, 'Closed for repairs (demo inactive room type).');

        // 4. Active owner whose resort is waiting for MTO review.
        $owner = $this->owner('owner.coralpoint', 'Joel', 'Castro', UserStatus::Active, 'Coral Point Leisure (Demo)', 'Authorized representative', 'Active owner, resort pending review', $password);
        $resort = $this->resort($owner, [
            'name' => 'Coral Point Resort (Demo)',
            'barangay' => 'Poblacion',
            'latitude' => 15.9930000,
            'longitude' => 119.8690000,
            'summary' => 'A fictional resort submitted for review.',
            'cover' => 'coral',
        ], ResortStatus::Pending);
        $this->amenities($resort, ['Beachfront', 'Restaurant']);
        $this->room($resort, 'Deluxe Room', RoomType::Premium, 2, 3000, 3);

        // 5. Owner application waiting for MTO review (cannot sign in).
        $this->owner('owner.pending', 'Ana', 'Mendoza', UserStatus::Pending, 'Sunrise Cove Resort (Demo)', 'Owner', 'Pending application: sign-in is refused', $password);

        // 6. Rejected owner application (cannot sign in).
        $this->owner('owner.rejected', 'Paolo', 'Garcia', UserStatus::Rejected, 'Unverified Beach Camp (Demo)', 'Manager', 'Rejected application: sign-in is refused', $password,
            remarks: 'DEMO: The business permit number could not be verified.');

        // 7. Deactivated owner: the resort is still "active" but must be hidden publicly.
        $owner = $this->owner('owner.inactive', 'Marco', 'Aquino', UserStatus::Inactive, 'Hidden Cove Holdings (Demo)', 'Owner', 'Inactive owner: active resort is hidden', $password);
        $resort = $this->resort($owner, [
            'name' => 'Hidden Cove Resort (Demo)',
            'barangay' => 'Alilao',
            'latitude' => 16.0150000,
            'longitude' => 119.8660000,
            'summary' => 'A fictional resort whose owner account is deactivated.',
            'cover' => 'dusk',
        ], ResortStatus::Active);
        $this->room($resort, 'Standard Room', RoomType::Budget, 2, 2000, 2);
    }

    private function seedTourists(string $password): void
    {
        $tourists = [
            ['tourist.bea', 'Bea', 'Santos', 'Manila', UserStatus::Active, 'Active tourist'],
            ['tourist.kevin', 'Kevin', 'Lim', 'Dagupan City', UserStatus::Active, 'Active tourist'],
            ['tourist.grace', 'Grace', 'Tan', 'Baguio City', UserStatus::Active, 'Active tourist'],
            ['tourist.mark', 'Mark', 'Dela Cruz', 'Quezon City', UserStatus::Active, 'Active tourist'],
            ['tourist.inactive', 'Rico', 'Bautista', 'Lingayen', UserStatus::Inactive, 'Inactive tourist: sign-in is refused'],
        ];

        foreach ($tourists as [$handle, $first, $last, $city, $status, $note]) {
            $user = $this->user($handle, $first, $last, UserRole::Tourist, $status, $password);
            $user->forceFill(['home_city' => $city, 'home_country' => 'Philippines'])->save();
            $this->accounts[] = [UserRole::Tourist->label(), $user->email, $status->label(), $note];
        }
    }

    private function seedDestinations(): void
    {
        $categories = DestinationCategory::query()->pluck('id', 'name');

        // Ten pins spread across different barangays and categories, so the
        // map, the category filter, and the listing all have something to show.
        // Nine are public; the last is hidden on purpose, to prove that an
        // inactive destination never reaches the tourist pages.
        $destinations = [
            ['Sample White Sand Beach (Demo)', 'Beach', 'Tambobong', 16.0110000, 119.8640000, DestinationStatus::Active, true, 'beach'],
            ['Sample Island Hopping Point (Demo)', 'Island', 'Uli', 15.9960000, 119.8600000, DestinationStatus::Active, false, 'island'],
            ['Sample Salt Farm Viewing Area (Demo)', 'Natural Attraction', 'Poblacion', 15.9870000, 119.8790000, DestinationStatus::Active, false, 'salt'],
            ['Sample Town Plaza (Demo)', 'Historical Site', 'Poblacion', 15.9905000, 119.8815000, DestinationStatus::Active, false, 'plaza'],
            ['Sample Hilltop Viewpoint (Demo)', 'Viewpoint', 'Hermosa', 15.9750000, 119.8950000, DestinationStatus::Active, true, 'hills'],
            ['Sample Mangrove Boardwalk (Demo)', 'Natural Attraction', 'Osmeña', 15.9820000, 119.8880000, DestinationStatus::Active, false, 'mangrove'],
            ['Sample Cove Viewdeck (Demo)', 'Viewpoint', 'Alilao', 16.0140000, 119.8610000, DestinationStatus::Active, false, 'cove'],
            ['Sample Heritage Chapel (Demo)', 'Historical Site', 'Malimpin', 15.9880000, 119.8960000, DestinationStatus::Active, false, 'plaza'],
            ['Sample Kayak Launch Point (Demo)', 'Recreation', 'Bobonot', 15.9700000, 119.8700000, DestinationStatus::Active, false, 'island'],
            ['Sample Hidden Falls (Demo)', 'Natural Attraction', 'Magsaysay', 15.9650000, 119.9050000, DestinationStatus::Inactive, false, 'falls'],
        ];

        foreach ($destinations as [$name, $category, $barangay, $lat, $lng, $status, $featured, $cover]) {
            $destination = new Destination([
                'category_id' => $categories[$category],
                'name' => $name,
                'description' => self::DESCRIPTION_PREFIX.'A placeholder destination for testing the tourism pages and map. '
                    .'Replace it with verified information from the Municipal Tourism Office.',
                'address' => 'Demo address',
                'barangay' => $barangay,
                'latitude' => $lat,
                'longitude' => $lng,
            ]);
            $destination->forceFill([
                'main_image' => $this->cover($cover),
                'status' => $status,
                'is_featured' => $featured,
                'created_by' => $this->mto?->id,
            ])->save();
        }
    }

    private function seedAnnouncements(): void
    {
        $items = [
            ['Welcome to Visit Dasol (Demo)', AnnouncementStatus::Published, now()->subDays(2), true],
            ['Sample advisory: office hours during holidays (Demo)', AnnouncementStatus::Published, now()->subDays(6), false],
            ['Draft: upcoming event details (Demo)', AnnouncementStatus::Draft, null, false],
        ];

        foreach ($items as [$title, $status, $publishedAt, $featured]) {
            $announcement = new Announcement([
                'title' => $title,
                'excerpt' => 'A fictional announcement used to test the announcements pages.',
                'body' => self::DESCRIPTION_PREFIX."This announcement is sample content for testing.\n\nIt does not describe a real advisory or event.",
            ]);
            $announcement->forceFill([
                'status' => $status,
                'published_at' => $publishedAt,
                'is_featured' => $featured,
                'created_by' => $this->mto?->id,
            ])->save();
        }
    }

    /**
     * Upcoming stays go through the real booking engine, so they respect
     * availability and every other rule. Past stays are written directly,
     * because the engine (rightly) refuses dates in the past.
     */
    private function seedBookings(): void
    {
        $service = app(BookingService::class);
        $references = app(BookingReferenceGenerator::class);
        $pricer = app(BookingPricer::class);
        $today = CarbonImmutable::today();

        $tourists = User::query()->tourists()->active()->orderBy('id')->get();
        $rooms = Room::query()->bookable()->with('resort')->orderBy('id')->get();

        if ($tourists->isEmpty() || $rooms->count() < 3) {
            return;
        }

        /*
         * Upcoming: two confirmed and one still waiting for the owner.
         * The parties differ on purpose — a family with a child and a
         * guest with a PWD ID — so a walkthrough shows the discount
         * lines rather than three identical bills for two adults.
         */
        $upcoming = [
            [$tourists[0], $rooms[0], 10, 13, BookingStatus::Confirmed, 2, 0, 0],
            [$tourists[1], $rooms[1], 20, 22, BookingStatus::Pending, 2, 2, 0],
            [$tourists[2], $rooms[2], 5, 7, BookingStatus::Confirmed, 2, 0, 1],
        ];

        foreach ($upcoming as [$tourist, $room, $startsIn, $endsIn, $status, $adults, $children, $pwd]) {
            // Never ask for a bigger party than the room can take.
            $adults = max(1, min($adults, $room->capacity));
            $children = max(0, min($children, $room->capacity - $adults));

            $booking = $service->create($tourist, $room->id, [
                'check_in_date' => $today->addDays($startsIn)->toDateString(),
                'check_out_date' => $today->addDays($endsIn)->toDateString(),
                'adults' => $adults,
                'children' => $children,
                'pwd_guests' => min($pwd, $adults + $children),
                'rooms_booked' => 1,
                'contact_name' => $tourist->full_name,
                'contact_phone' => $tourist->phone,
                'special_requests' => 'DEMO DATA. Sample reservation for testing.',
            ]);

            if ($status === BookingStatus::Confirmed) {
                $booking->forceFill(['status' => $status, 'confirmed_at' => now()])->save();
            }
        }

        // Past: one completed stay and one the guest cancelled.
        $past = [
            [$tourists[3] ?? $tourists[0], $rooms[0], 20, 17, BookingStatus::Completed],
            [$tourists[0], $rooms[1], 40, 38, BookingStatus::Cancelled],
        ];

        foreach ($past as [$tourist, $room, $startedAgo, $endedAgo, $status]) {
            $checkIn = $today->subDays($startedAgo);
            $checkOut = $today->subDays($endedAgo);
            $nights = (int) $checkIn->diffInDays($checkOut);

            $booking = new Booking([
                'contact_name' => $tourist->full_name,
                'contact_phone' => $tourist->phone,
                'special_requests' => 'DEMO DATA. Sample past reservation.',
            ]);

            $adults = min(2, $room->capacity);

            /*
             * Priced the same way a real booking is, even though these
             * are written straight to the table — the engine refuses
             * dates in the past, but the bill still has to add up on
             * the page that shows it.
             */
            $price = $pricer->quote($room, $nights, 1, $adults, 0, 0);

            $booking->forceFill([
                'booking_reference' => $references->generate($checkIn),
                'user_id' => $tourist->id,
                'resort_id' => $room->resort_id,
                'room_id' => $room->id,
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'check_in_time' => $room->resort->check_in_time,
                'check_out_time' => $room->resort->check_out_time,
                'nights' => $nights,
                'guests' => $adults,
                'adults' => $adults,
                'children' => 0,
                'pwd_guests' => 0,
                'rooms_booked' => 1,
                'beds' => $room->bed_count,
                'unit_price' => $room->price_per_night,
                'subtotal_amount' => $price->subtotal,
                'total_amount' => $price->total,
                'status' => $status,
                'confirmed_at' => $status === BookingStatus::Completed ? $checkIn->subDays(3) : null,
                'completed_at' => $status === BookingStatus::Completed ? $checkOut : null,
                'cancelled_at' => $status === BookingStatus::Cancelled ? $checkIn->subDays(5) : null,
                'cancelled_by' => $status === BookingStatus::Cancelled ? $tourist->id : null,
                'cancellation_reason' => $status === BookingStatus::Cancelled ? 'DEMO DATA. Change of plans.' : null,
                'created_at' => $checkIn->subDays(14),
            ])->save();
        }
    }

    /* Helpers ------------------------------------------------------------ */

    /**
     * Draws a cover image for a demo listing and returns its path on the
     * public disk.
     *
     * Drawn here rather than shipped as files because storage/app/public is
     * not in version control: a fresh clone that seeds would otherwise show
     * nothing but grey placeholders. Existing files are left alone, so
     * re-seeding does not redraw them.
     */
    private function cover(string $key): ?string
    {
        if (! isset(self::COVERS[$key]) || ! extension_loaded('gd')) {
            return null;
        }

        $path = "demo/{$key}.jpg";
        $disk = Storage::disk('public');

        if ($disk->exists($path)) {
            return $path;
        }

        [$sky, $water, $land] = self::COVERS[$key];

        $width = 1200;
        $height = 800;
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, true);

        // Sky fading down into the water colour.
        for ($y = 0; $y < $height; $y++) {
            $t = $y / $height;
            $shade = imagecolorallocate(
                $canvas,
                (int) ($sky[0] + ($water[0] - $sky[0]) * $t),
                (int) ($sky[1] + ($water[1] - $sky[1]) * $t),
                (int) ($sky[2] + ($water[2] - $sky[2]) * $t),
            );
            imagefilledrectangle($canvas, 0, $y, $width, $y, $shade);
        }

        // A low sun, sitting off to one side.
        $sun = imagecolorallocatealpha($canvas, 255, 240, 205, 62);
        imagefilledellipse($canvas, (int) ($width * 0.74), (int) ($height * 0.30), 200, 200, $sun);

        // Three ridges, each lower and more solid than the one above it.
        foreach ([[0.60, 78], [0.72, 44], [0.85, 0]] as $index => [$base, $alpha]) {
            $points = [];

            for ($x = 0; $x <= $width; $x += 24) {
                $points[] = $x;
                $points[] = (int) ($height * $base + sin($x / 170 + $index * 1.7) * 36);
            }

            array_push($points, $width, $height, 0, $height);

            imagefilledpolygon($canvas, $points, imagecolorallocatealpha($canvas, $land[0], $land[1], $land[2], $alpha));
        }

        ob_start();
        imagejpeg($canvas, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        $disk->put($path, $bytes);

        return $path;
    }

    private function user(string $handle, string $first, string $last, UserRole $role, UserStatus $status, string $password): User
    {
        $user = new User([
            'first_name' => $first,
            'last_name' => $last.' (Demo)',
            'email' => $handle.'@'.config('visitdasol.demo.email_domain'),
            'phone' => '0917'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT),
            'password' => $password,
        ]);
        $user->role = $role;
        $user->status = $status;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function owner(
        string $handle,
        string $first,
        string $last,
        UserStatus $status,
        string $businessName,
        string $position,
        string $note,
        string $password,
        ?string $remarks = null,
    ): User {
        $owner = $this->user($handle, $first, $last, UserRole::ResortOwner, $status, $password);

        $profile = new OwnerProfile([
            'business_name' => $businessName,
            'position' => $position,
            'business_permit_no' => 'DEMO-'.str_pad((string) $owner->id, 5, '0', STR_PAD_LEFT),
        ]);
        $profile->user()->associate($owner);

        if ($status !== UserStatus::Pending) {
            $profile->forceFill([
                'reviewed_by' => $this->mto?->id,
                'reviewed_at' => now()->subDays(10),
                'review_remarks' => $remarks,
            ]);
        }
        $profile->save();

        $this->accounts[] = [UserRole::ResortOwner->label(), $owner->email, $status->label(), $note];

        return $owner;
    }

    /**
     * @param  array{name: string, barangay: string, latitude: float, longitude: float, summary: string, cover?: string}  $data
     */
    private function resort(User $owner, array $data, ResortStatus $status, bool $featured = false): Resort
    {
        $resort = new Resort([
            'name' => $data['name'],
            'description' => self::DESCRIPTION_PREFIX.$data['summary']
                .' This listing is not real; its name, rooms, rates, and location were made up for testing.',
            'address' => 'Demo address',
            'barangay' => $data['barangay'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'contact_number' => '0917'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT),
            'email' => 'contact.'.$owner->id.'@'.config('visitdasol.demo.email_domain'),
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
        ]);

        $resort->owner()->associate($owner);
        $resort->forceFill([
            'main_image' => $this->cover($data['cover'] ?? 'beach'),
            'status' => $status,
            'is_featured' => $featured,
            // Keeps this listing off the public pages unless
            // DEMO_LISTINGS_PUBLIC says otherwise. See Resort::publiclyVisible.
            'is_demo' => true,
            'submitted_at' => now()->subDays(14),
            'reviewed_by' => $status === ResortStatus::Pending ? null : $this->mto?->id,
            'reviewed_at' => $status === ResortStatus::Pending ? null : now()->subDays(7),
        ])->save();

        return $resort;
    }

    /**
     * @param  list<string>  $names
     */
    private function amenities(Resort $resort, array $names): void
    {
        // Demo resorts receive their own amenity rows. Never attach a shared
        // catalogue row to multiple resorts: owners manage these independently.
        $sourceAmenities = Amenity::query()->whereIn('name', $names)->get();
        $ownedAmenityIds = [];
        foreach ($sourceAmenities as $source) {
            $owned = $resort->amenities()->where('amenities.name', $source->name)->first();

            if ($owned === null) {
                $owned = Amenity::query()->create([
                    'name' => $source->name,
                    'icon' => $source->icon,
                    'is_active' => true,
                ]);
                $resort->amenities()->attach($owned->id);
            }

            $ownedAmenityIds[] = $owned->id;
        }

        $resort->amenities()->sync($ownedAmenityIds);
    }

    private function room(
        Resort $resort,
        string $name,
        RoomType $type,
        int $capacity,
        int $price,
        int $units,
        RoomStatus $status = RoomStatus::Active,
        ?string $description = null,
    ): Room {
        /*
         * Enough beds for the room's capacity, in doubles where that
         * divides evenly, so a walkthrough shows a bed count that reads
         * like a real one rather than one bed per guest everywhere.
         */
        $beds = max(1, (int) ceil($capacity / 2));

        /*
         * Half the demo rooms carry a discount policy, so a walkthrough
         * shows both the rooms that discount and the rooms that do not.
         * The PWD rate is the 20% Philippine law provides for.
         */
        $discounts = $capacity % 2 === 0;

        $room = new Room([
            'name' => $name,
            'room_type' => $type,
            'description' => self::DESCRIPTION_PREFIX.($description ?? "Sample {$type->label()} room for up to {$capacity} guests."),
            'capacity' => $capacity,
            'bed_count' => $beds,
            'price_per_night' => $price,
            'total_rooms' => $units,

            'child_discount_enabled' => $discounts,
            'child_discount_percent' => $discounts ? 50 : 0,
            'child_discount_min_age' => 0,
            'child_discount_max_age' => 12,

            'pwd_discount_enabled' => $discounts,
            'pwd_discount_percent' => $discounts ? 20 : 0,
        ]);
        $room->resort()->associate($resort);
        $room->status = $status;
        $room->save();

        return $room;
    }
}
