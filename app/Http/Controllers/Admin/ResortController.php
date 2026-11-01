<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ResortStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeactivateResortRequest;
use App\Http\Requests\Admin\UpdateResortRequest;
use App\Models\ActivityLog;
use App\Models\Amenity;
use App\Models\Resort;
use App\Services\ActivityLogger;
use App\Services\ImageUploadService;
use App\Services\ResortManager;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Every resort, whatever its status, for the Municipal Tourism Office.
 */
class ResortController extends Controller
{
    public function __construct(
        private readonly ResortManager $resorts,
        private readonly ImageUploadService $images,
        private readonly ActivityLogger $logger,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Resort::class);

        $status = ResortStatus::tryFrom((string) $request->query('status'));
        $barangay = in_array($request->query('barangay'), config('visitdasol.barangays'), true)
            ? $request->query('barangay')
            : null;
        $search = trim((string) $request->query('q', ''));

        // "featured" is the office's own running order, so it stays the
        // default: featured first, then alphabetical. Choosing any other
        // column replaces it rather than adding to it, or the sort the reader
        // asked for would be overruled by a flag they cannot see.
        $sort = TableSort::fromRequest($request, [
            'featured' => ['-is_featured', 'name'],
            'name' => 'name',
            'barangay' => ['barangay', 'name'],
            'status' => 'status',
            'rooms' => '-rooms_count',
            'added' => '-created_at',
        ], default: 'featured');

        return view('admin.resorts.index', [
            'resorts' => Resort::query()
                ->when($status, fn ($query) => $query->withStatus($status))
                ->when($barangay, fn ($query) => $query->where('barangay', $barangay))
                ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search) {
                    $match->where('name', 'like', "%{$search}%")
                        ->orWhereHas('owner', fn ($owner) => $owner
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"));
                }))
                ->with('owner')
                ->withCount('rooms')
                ->tap(fn ($query) => $sort->apply($query))
                ->paginate(config('visitdasol.per_page'))
                ->withQueryString(),
            'status' => $status,
            'barangay' => $barangay,
            'search' => $search,
            'sort' => $sort,
            'barangays' => config('visitdasol.barangays'),
        ]);
    }

    public function show(Resort $resort): View
    {
        $this->authorize('review', $resort);

        $resort->load(['owner.ownerProfile', 'images', 'amenities', 'rooms']);

        return view('admin.resorts.show', [
            'resort' => $resort,
            'history' => ActivityLog::query()
                ->where('subject_type', $resort->getMorphClass())
                ->where('subject_id', $resort->id)
                ->with('user')
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    public function edit(Resort $resort): View
    {
        $this->authorize('update', $resort);

        $resort->load('amenities');

        return view('admin.resorts.edit', [
            'resort' => $resort,
            'barangays' => config('visitdasol.barangays'),
            'amenities' => $resort->amenities()->orderBy('name')->get(),
            'selectedAmenities' => $resort->amenities->pluck('id')->all(),
        ]);
    }

    /**
     * The office can correct details (a wrong pin, a typo). This never
     * changes the listing's status: use approve, reject, activate, or
     * deactivate for that.
     */
    public function update(UpdateResortRequest $request, Resort $resort): RedirectResponse
    {
        $this->authorize('update', $resort);

        $resort->fill($request->resortData());
        $changed = array_keys($resort->getDirty());

        DB::transaction(function () use ($request, $resort): void {
            if ($request->boolean('remove_main_image')) {
                $this->images->delete($resort->main_image);
                $resort->forceFill(['main_image' => null]);
            }

            if ($request->hasFile('main_image')) {
                $resort->forceFill([
                    'main_image' => $this->images->replace(
                        $resort->main_image,
                        $request->file('main_image'),
                        "resorts/{$resort->id}/cover"
                    ),
                ]);
            }

            $resort->save();
            $resort->amenities()->sync($request->amenityIds());
        });

        $this->logger->log('resort.edited_by_office', $resort, "Edited the resort {$resort->name}", [
            'fields' => $changed,
        ]);

        return redirect()->route('admin.resorts.show', $resort)->with('success', 'The listing has been updated.');
    }

    public function activate(Resort $resort): RedirectResponse
    {
        $this->authorize('activate', $resort);

        $this->resorts->activate($resort);

        $note = $resort->owner?->isActive()
            ? ''
            : ' Note: the owner account is not active, so the listing stays hidden until it is.';

        return back()->with('success', "{$resort->name} is public again.".$note);
    }

    public function deactivate(DeactivateResortRequest $request, Resort $resort): RedirectResponse
    {
        $this->authorize('deactivate', $resort);

        $this->resorts->deactivate($resort, $request->validated('review_remarks'));

        return back()->with('success', "{$resort->name} has been taken offline. Its owner can see your reason.");
    }

    public function feature(Resort $resort, Request $request): RedirectResponse
    {
        $this->authorize('feature', $resort);

        $featured = ! $resort->is_featured;
        $this->resorts->setFeatured($resort, $featured);

        return back()->with('success', $featured
            ? "{$resort->name} is now highlighted on the home page."
            : "{$resort->name} is no longer highlighted.");
    }
}
