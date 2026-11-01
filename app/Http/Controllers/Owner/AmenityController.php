<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\AmenityRequest;
use App\Models\Amenity;
use App\Models\Resort;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AmenityController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    private function ownedResort(Request $request): Resort
    {
        $resort = $request->user()->resort()->first();
        abort_if($resort === null, 404, 'Create your resort profile first.');

        return $resort;
    }

    private function ownedAmenity(Resort $resort, Amenity $amenity): Amenity
    {
        // A guessed ID belonging to another resort is indistinguishable
        // from a missing record.
        return $resort->amenities()->whereKey($amenity->getKey())->firstOrFail();
    }

    public function index(Request $request): View|RedirectResponse
    {
        $resort = $this->ownedResort($request);
        $amenities = $resort->amenities()->orderBy('name')->get();

        return view('owner.amenities.index', compact('resort', 'amenities'));
    }

    public function store(AmenityRequest $request): RedirectResponse
    {
        $resort = $this->ownedResort($request);
        $data = $request->validated();

        $amenity = DB::transaction(function () use ($resort, $data): Amenity {
            $amenity = Amenity::create([
                'name' => $data['name'],
                'icon' => $data['icon'] ?? null,
                'is_active' => true,
            ]);
            $resort->amenities()->attach($amenity->id);

            return $amenity;
        });

        $this->logger->log('amenity.added', $amenity, 'Added "'.$amenity->name.'" to '.$resort->name, [
            'resort_id' => $resort->id,
        ]);

        return redirect()->route('owner.amenities.index')
            ->with('success', '"'.$amenity->name.'" has been added to your resort.');
    }

    public function update(AmenityRequest $request, Amenity $amenity): RedirectResponse
    {
        $resort = $this->ownedResort($request);
        $amenity = $this->ownedAmenity($resort, $amenity);
        $amenity->update($request->validated());

        $this->logger->log('amenity.updated', $amenity, 'Updated "'.$amenity->name.'" on '.$resort->name, [
            'resort_id' => $resort->id,
        ]);

        return redirect()->route('owner.amenities.index')->with('success', 'Amenity updated.');
    }

    public function toggle(Request $request, Amenity $amenity): RedirectResponse
    {
        $resort = $this->ownedResort($request);
        $amenity = $this->ownedAmenity($resort, $amenity);
        $amenity->update(['is_active' => ! $amenity->is_active]);

        $this->logger->log('amenity.status_changed', $amenity, 'Changed amenity status for '.$resort->name, [
            'resort_id' => $resort->id,
            'is_active' => $amenity->is_active,
        ]);

        return redirect()->route('owner.amenities.index')->with('success', 'Amenity visibility updated.');
    }

    public function destroy(Request $request, Amenity $amenity): RedirectResponse
    {
        $resort = $this->ownedResort($request);
        $amenity = $this->ownedAmenity($resort, $amenity);

        DB::transaction(function () use ($resort, $amenity): void {
            $resort->amenities()->detach($amenity->id);
            $amenity->delete();
        });

        $this->logger->log('amenity.deleted', $amenity, 'Deleted an amenity from '.$resort->name, [
            'resort_id' => $resort->id,
        ]);

        return redirect()->route('owner.amenities.index')->with('success', 'Amenity deleted from your resort.');
    }
}
