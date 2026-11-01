<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ResortStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ResortRequest;
use App\Models\Amenity;
use App\Models\Resort;
use App\Services\ActivityLogger;
use App\Services\ImageUploadService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The owner's own resort. There is no resort id anywhere in these URLs:
 * the resort is always looked up from the signed-in owner, so one owner
 * can never reach another owner's listing by changing the address.
 *
 * A new resort is always "pending". Submitting the form never sets a status.
 */
class ResortController extends Controller
{
    public function __construct(
        private readonly ImageUploadService $images,
        private readonly ActivityLogger $logger,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $resort = $request->user()->resort()->with(['amenities', 'images', 'rooms'])->first();

        if ($resort === null) {
            return redirect()->route('owner.resort.create');
        }

        $this->authorize('view', $resort);

        return view('owner.resort.show', ['resort' => $resort]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()->resorts()->exists()) {
            return redirect()->route('owner.resort.show')
                ->with('info', 'You already have a resort. You can edit it here.');
        }

        $this->authorize('create', Resort::class);

        return view('owner.resort.create', $this->formData(new Resort));
    }

    public function store(ResortRequest $request): RedirectResponse
    {
        $this->authorize('create', Resort::class);

        $resort = new Resort($request->resortData());
        $resort->owner()->associate($request->user());
        $resort->status = ResortStatus::Pending;
        $resort->submitted_at = now();

        $storedPath = null;

        try {
            DB::transaction(function () use ($request, $resort, &$storedPath): void {
                $resort->save();
                $resort->amenities()->sync($request->amenityIds());

                if ($request->hasFile('main_image')) {
                    $storedPath = $this->images->store($request->file('main_image'), "resorts/{$resort->id}/cover");
                    $resort->forceFill(['main_image' => $storedPath])->save();
                }
            });
        } catch (Throwable $e) {
            $this->images->delete($storedPath);

            throw $e;
        }

        $this->logger->log('resort.submitted', $resort, "Submitted the resort {$resort->name} for review", [
            'status' => $resort->status->value,
        ]);

        return redirect()->route('owner.resort.show')
            ->with('success', 'Your resort has been sent to the Municipal Tourism Office for review.');
    }

    public function edit(Request $request): View|RedirectResponse
    {
        $resort = $request->user()->resort()->with('amenities')->first();

        if ($resort === null) {
            return redirect()->route('owner.resort.create');
        }

        $this->authorize('update', $resort);

        return view('owner.resort.edit', $this->formData($resort));
    }

    /**
     * Edits never change the status:
     * pending stays pending, rejected stays rejected until the owner
     * resubmits, and an approved resort stays live (the change is logged).
     */
    public function update(ResortRequest $request): RedirectResponse
    {
        $resort = $request->user()->resort()->first();
        abort_if($resort === null, 404);

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

        if ($resort->status === ResortStatus::Active) {
            $this->logger->log('resort.updated', $resort, "Updated the published resort {$resort->name}", [
                'fields' => $changed,
            ]);
        }

        return redirect()->route('owner.resort.show')->with('success', 'Your resort details have been saved.');
    }

    /** Send a rejected resort back to the office for another review. */
    public function resubmit(Request $request): RedirectResponse
    {
        $resort = $request->user()->resort()->first();
        abort_if($resort === null, 404);

        $this->authorize('resubmit', $resort);

        $resort->forceFill([
            'status' => ResortStatus::Pending,
            'submitted_at' => now(),
            'reviewed_at' => null,
            'reviewed_by' => null,
        ])->save();

        $this->logger->logStatusChange('resort.resubmitted', $resort, "Resubmitted the resort {$resort->name}", 'rejected', 'pending');

        return redirect()->route('owner.resort.show')
            ->with('success', 'Your resort has been sent back to the tourism office for review.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Resort $resort): array
    {
        return [
            'resort' => $resort,
            'barangays' => config('visitdasol.barangays'),
            'amenities' => $resort->exists ? $resort->amenities()->orderBy('name')->get() : collect(),
            'selectedAmenities' => $resort->exists ? $resort->amenities->pluck('id')->all() : [],
        ];
    }
}
