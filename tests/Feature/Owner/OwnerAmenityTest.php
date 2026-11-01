<?php

namespace Tests\Feature\Owner;

use App\Models\Amenity;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerAmenityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
    }

    public function test_owner_can_add_an_amenity_to_only_their_resort(): void
    {
        $otherOwner = User::factory()->resortOwner()->create();
        $otherResort = Resort::factory()->for($otherOwner, 'owner')->active()->create();

        $this->actingAs($this->owner)->post(route('owner.amenities.store'), [
            'name' => 'Kayak rental', 'icon' => 'life-preserver',
        ])->assertRedirect(route('owner.amenities.index'))->assertSessionHasNoErrors();

        $amenity = Amenity::sole();
        $this->assertTrue($this->resort->fresh()->amenities->contains($amenity));
        $this->assertFalse($otherResort->fresh()->amenities->contains($amenity));
    }

    public function test_owner_can_edit_toggle_and_delete_their_own_amenity(): void
    {
        $amenity = Amenity::factory()->create(['name' => 'Old name']);
        $this->resort->amenities()->attach($amenity);

        $this->actingAs($this->owner)->put(route('owner.amenities.update', $amenity), [
            'name' => 'New name', 'icon' => 'wifi',
        ])->assertRedirect(route('owner.amenities.index'));

        $this->assertSame('New name', $amenity->fresh()->name);

        $this->actingAs($this->owner)->patch(route('owner.amenities.toggle', $amenity))
            ->assertRedirect(route('owner.amenities.index'));
        $this->assertFalse($amenity->fresh()->is_active);

        $this->actingAs($this->owner)->delete(route('owner.amenities.destroy', $amenity))
            ->assertRedirect(route('owner.amenities.index'));
        $this->assertDatabaseMissing('amenities', ['id' => $amenity->id]);
    }

    public function test_owner_cannot_edit_toggle_or_delete_another_resorts_amenity(): void
    {
        $otherOwner = User::factory()->resortOwner()->create();
        $otherResort = Resort::factory()->for($otherOwner, 'owner')->active()->create();
        $amenity = Amenity::factory()->create(['name' => 'Other resort amenity']);
        $otherResort->amenities()->attach($amenity);

        $this->actingAs($this->owner)->put(route('owner.amenities.update', $amenity), [
            'name' => 'Hijacked', 'icon' => 'wifi',
        ])->assertNotFound();

        $this->actingAs($this->owner)->patch(route('owner.amenities.toggle', $amenity))->assertNotFound();
        $this->actingAs($this->owner)->delete(route('owner.amenities.destroy', $amenity))->assertNotFound();

        $this->assertSame('Other resort amenity', $amenity->fresh()->name);
        $this->assertTrue($otherResort->fresh()->amenities->contains($amenity));
    }

    public function test_duplicate_names_are_allowed_across_resorts_but_not_within_one_resort(): void
    {
        $otherOwner = User::factory()->resortOwner()->create();
        $otherResort = Resort::factory()->for($otherOwner, 'owner')->active()->create();
        $first = Amenity::factory()->create(['name' => 'Wi-Fi']);
        $this->resort->amenities()->attach($first);

        $this->actingAs($this->owner)->post(route('owner.amenities.store'), ['name' => 'Wi-Fi'])
            ->assertSessionHasErrors('name');
        $this->assertSame(1, Amenity::count());

        $second = Amenity::factory()->create(['name' => 'Wi-Fi']);
        $otherResort->amenities()->attach($second);
        $this->assertSame(2, Amenity::where('name', 'Wi-Fi')->count());
    }

    public function test_owner_page_only_shows_their_resorts_amenities(): void
    {
        $mine = Amenity::factory()->create(['name' => 'Swimming pool']);
        $theirs = Amenity::factory()->create(['name' => 'Jet ski']);
        $this->resort->amenities()->attach($mine);

        $this->actingAs($this->owner)->get(route('owner.amenities.index'))
            ->assertOk()->assertSee('Swimming pool')->assertDontSee('Jet ski')
            ->assertSee('Add an amenity');
    }

    public function test_tourists_cannot_manage_amenities(): void
    {
        $tourist = User::factory()->create();
        $this->actingAs($tourist)->get(route('owner.amenities.index'))->assertForbidden();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('owner.amenities.index'))->assertRedirect(route('login'));
    }
}
