<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AmenityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->input('name'))]);
        }
        if (is_string($this->input('icon'))) {
            $this->merge(['icon' => Str::lower(trim($this->input('icon'))) ?: null]);
        }
    }

    public function rules(): array
    {
        $resortId = $this->user()?->resort()->value('id');
        $amenityId = $this->route('amenity')?->id;

        return [
            'name' => [
                'required', 'string', 'min:2', 'max:80',
                Rule::unique('amenities', 'name')
                    ->where(fn ($query) => $query->whereIn('id', function ($subquery) use ($resortId) {
                        $subquery->select('amenity_id')->from('amenity_resort')
                            ->where('resort_id', $resortId);
                    }))
                    ->ignore($amenityId),
            ],
            'icon' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'You already added an amenity with this name to your resort.',
            'icon.regex' => 'Use a Bootstrap Icons name such as "wifi" or "cup-hot".',
        ];
    }
}
