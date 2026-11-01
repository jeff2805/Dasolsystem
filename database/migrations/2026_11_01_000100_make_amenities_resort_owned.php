<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split the former municipality-wide amenity catalogue into resort-owned
 * entries. Existing pivot assignments are copied, not discarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The old global unique name constraint must go: different resorts
        // are allowed to use the same amenity label independently.
        Schema::table('amenities', function (Blueprint $table) {
            $table->dropUnique('amenities_name_unique');
        });

        DB::transaction(function (): void {
            $oldAmenities = DB::table('amenities')->orderBy('id')->get();

            foreach ($oldAmenities as $old) {
                $assignments = DB::table('amenity_resort')
                    ->where('amenity_id', $old->id)
                    ->orderBy('resort_id')
                    ->pluck('resort_id');

                // One independent amenity row per resort that previously
                // selected the shared entry.
                foreach ($assignments as $resortId) {
                    $newId = DB::table('amenities')->insertGetId([
                        'name' => $old->name,
                        'icon' => $old->icon,
                        'is_active' => $old->is_active,
                        'created_at' => $old->created_at,
                        'updated_at' => $old->updated_at,
                    ]);

                    DB::table('amenity_resort')
                        ->where('amenity_id', $old->id)
                        ->where('resort_id', $resortId)
                        ->update(['amenity_id' => $newId]);
                }

                // The old shared row is no longer used. Rows not selected
                // by any resort are intentionally retired.
                DB::table('amenities')->where('id', $old->id)->delete();
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-reversible: merging independently edited,
        // same-named resort amenities back into one shared row is ambiguous
        // and could silently destroy resort-specific data.
    }
};
