<?php

namespace App\Services\OTA\Flights\Presentation\API\V2\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'OriginIataCode' => 'required|string|size:3',
            'DestinationIataCode' => 'required|string|size:3',
            'DepartureDate' => 'required|date',
            'ReturningDate' => 'nullable|date',
            'FetchSupplierWebserviceFlights' => 'boolean',
            'FetchFlighsWithBookingPolicy' => 'boolean',
            'Language' => 'string|in:FA,EN',
        ];
    }

    public function toArray(): array
    {
        return [
            'OriginIataCode' => $this->input('OriginIataCode'),
            'DestinationIataCode' => $this->input('DestinationIataCode'),
            'DepartureDate' => $this->input('DepartureDate'),
            'ReturningDate' => $this->input('ReturningDate'),
            'FetchSupplierWebserviceFlights' => $this->boolean('FetchSupplierWebserviceFlights', false),
            'FetchFlighsWithBookingPolicy' => $this->boolean('FetchFlighsWithBookingPolicy', true),
            'Language' => $this->input('Language', 'FA'),
        ];
    }
}