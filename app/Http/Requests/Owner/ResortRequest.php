<?php

namespace App\Http\Requests\Owner;

use App\Http\Requests\Concerns\ValidatesImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Used for both registering and editing a resort.
 *
 * owner_id, status, slug, and the review fields are NOT here on purpose:
 * the controller sets them. Anything else posted with the form is ignored.
 */
class ResortRequest extends FormRequest
{
    use ValidatesImages;

    public function authorize(): bool
    {
        return true; // The controller authorizes against ResortPolicy.
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['name', 'address', 'barangay'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = Str::squish($this->input($field));
            }
        }

        if (is_string($this->input('contact_number'))) {
            $clean['contact_number'] = preg_replace('/[\s\-().]/', '', $this->input('contact_number'));
        }

        if (is_string($this->input('email'))) {
            $clean['email'] = Str::lower(trim($this->input('email')));
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $bounds = config('visitdasol.map.bounds');
        // Owner forms resolve their own resort from the signed-in account;
        // the MTO edit route supplies the resort explicitly.
        $amenityResortId = $this->route('resort')?->id
            ?? $this->user()?->resort()->value('id');

        return [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['required', 'string', 'min:40', 'max:5000'],
            'address' => ['required', 'string', 'max:255'],
            'barangay' => ['required', 'string', Rule::in(config('visitdasol.barangays'))],
            'latitude' => ['required', 'numeric', 'between:'.$bounds['south'].','.$bounds['north']],
            'longitude' => ['required', 'numeric', 'between:'.$bounds['west'].','.$bounds['east']],
            'contact_number' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'website_url' => ['nullable', 'url:http,https', 'max:255'],
            'check_in_time' => ['required', 'date_format:H:i'],
            'check_out_time' => ['required', 'date_format:H:i'],
            'amenities' => ['nullable', 'array', 'max:40'],
            'amenities.*' => [
                Rule::exists('amenity_resort', 'amenity_id')->where(
                    'resort_id',
                    $amenityResortId
                ),
            ],
            'main_image' => ['nullable', ...$this->imageRules()],
            'remove_main_image' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'description.min' => 'Please describe the resort in at least a couple of sentences.',
            'barangay.in' => 'Choose one of the 18 barangays of Dasol.',
            'latitude.between' => 'Place the map pin inside Dasol.',
            'longitude.between' => 'Place the map pin inside Dasol.',
            'contact_number.regex' => 'Enter a valid contact number, for example 09171234567.',
            'website_url.url' => 'Enter a full web address starting with https://',
            'check_in_time.date_format' => 'Use the time picker, for example 2:00 PM.',
            'check_out_time.date_format' => 'Use the time picker, for example 12:00 PM.',
            'amenities.*.exists' => 'Choose amenities belonging to your own resort.',
        ];

        foreach ($this->imageMessages('cover photo') as $rule => $message) {
            $messages["main_image.{$rule}"] = $message;
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'main_image' => 'cover photo',
            'website_url' => 'website or Facebook page',
            'check_in_time' => 'check-in time',
            'check_out_time' => 'check-out time',
        ];
    }

    /**
     * Only the resort columns, ready for fill().
     *
     * @return array<string, mixed>
     */
    public function resortData(): array
    {
        return $this->safe()->only([
            'name', 'description', 'address', 'barangay', 'latitude', 'longitude',
            'contact_number', 'email', 'website_url', 'check_in_time', 'check_out_time',
        ]);
    }

    /**
     * @return list<int>
     */
    public function amenityIds(): array
    {
        return collect($this->safe()->input('amenities', []))->map(fn ($id) => (int) $id)->all();
    }
}
