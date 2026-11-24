<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * Shared server-side rules for optional coordinates (map picker or manual entry).
 *
 *  - latitude/longitude are optional, but always both or neither, and in range;
 *  - when coordinates are given, explicit consent ("location_consent") is required.
 *    ("accepted" is an implicit rule that would fire even when the box is absent,
 *    so "in" is used together with requiredIf.)
 */
trait ValidatesLocation
{
    protected function locationRules(): array
    {
        $hasLocation = fn () => $this->filled('latitude') || $this->filled('longitude');

        return [
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'location_consent' => [Rule::requiredIf($hasLocation), 'nullable', 'in:1,on,yes,true'],
        ];
    }

    protected function locationMessages(): array
    {
        return [
            'latitude.required_with' => 'Enter both latitude and longitude, or leave both empty.',
            'longitude.required_with' => 'Enter both latitude and longitude, or leave both empty.',
            'latitude.between' => 'Latitude must be between -90 and 90.',
            'longitude.between' => 'Longitude must be between -180 and 180.',
            'location_consent.required' => 'Confirm consent to store the location, or clear the coordinates.',
            'location_consent.in' => 'Confirm consent to store the location, or clear the coordinates.',
        ];
    }

    public function locationConsent(): bool
    {
        return $this->boolean('location_consent');
    }
}
