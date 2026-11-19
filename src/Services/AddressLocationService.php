<?php

namespace iEducar\Packages\Transport\Services;

use clsModulesPontoTransporteEscolar;
use iEducar\Packages\Transport\View\TransportMap;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AddressLocationService
{
    public const SEARCH_URL = 'https://api.openrouteservice.org/geocode/search/structured';

    public const COUNTRY = 'BR';

    public const API_RESOURCE = 'localizacao';

    public const LOG_FAILURE_MESSAGE = 'OpenRouteService geocode request failed';

    public const STATUS_ADDRESS = 'address';

    public const STATUS_STREET = 'street';

    public const STATUS_NEIGHBOURHOOD = 'neighbourhood';

    public const STATUS_CITY = 'city';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const LAYER_STATUSES = [
        'address' => self::STATUS_ADDRESS,
        'street' => self::STATUS_STREET,
        'neighbourhood' => self::STATUS_NEIGHBOURHOOD,
        'locality' => self::STATUS_CITY,
        'localadmin' => self::STATUS_CITY,
        'county' => self::STATUS_CITY,
    ];

    private const STREET_SEARCH_STATUSES = [self::STATUS_ADDRESS, self::STATUS_STREET, self::STATUS_NEIGHBOURHOOD];

    private const REGION_SEARCH_STATUSES = [self::STATUS_NEIGHBOURHOOD, self::STATUS_CITY];

    public const RESULT_COUNT = 5;

    private const LABEL_SEPARATOR = ', ';

    private const CITY_PROPERTIES = ['locality', 'localadmin', 'county'];

    private const STATE_PROPERTY = 'region_a';

    public function __construct(private readonly ?array $view = null) {}

    public function locate(array $address): array
    {
        $hasPlace = self::hasStreet($address) || !blank($address['neighborhood'] ?? null);

        if (!$hasPlace || blank($address['city'] ?? null) || blank($address['state'] ?? null)) {
            return ['status' => self::STATUS_NOT_FOUND];
        }

        if (!RoutePathService::hasKey()) {
            return ['status' => self::STATUS_UNAVAILABLE];
        }

        $features = $this->features($address);

        if ($features === null) {
            return ['status' => self::STATUS_UNAVAILABLE];
        }

        $feature = self::firstFeatureInCity($features, $address['city'], $address['state'], self::acceptedStatuses($address));

        if ($feature === null) {
            return ['status' => self::STATUS_NOT_FOUND];
        }

        return $this->result($feature);
    }

    private static function hasStreet(array $address): bool
    {
        return !blank($address['address'] ?? null);
    }

    private static function acceptedStatuses(array $address): array
    {
        return self::hasStreet($address) ? self::STREET_SEARCH_STATUSES : self::REGION_SEARCH_STATUSES;
    }

    private static function searchFields(array $address): array
    {
        $fields = array_map(fn ($value) => implode(self::LABEL_SEPARATOR, self::filledParts((array) $value)), [
            'address' => self::hasStreet($address) ? [$address['address'], $address['number'] ?? null] : [],
            'neighbourhood' => self::hasStreet($address) ? null : ($address['neighborhood'] ?? null),
            'locality' => $address['city'],
            'region' => $address['state'],
        ]);

        return array_filter($fields, fn (string $value) => $value !== '') + ['country' => self::COUNTRY];
    }

    private function features(array $address): ?array
    {
        try {
            $response = Http::withHeaders(['Authorization' => RoutePathService::key()])
                ->acceptJson()
                ->timeout(RoutePathService::TIMEOUT_SECONDS)
                ->get(self::SEARCH_URL, $this->query($address));
        } catch (ConnectionException) {
            RoutePathService::logFailure(['reason' => 'connection'], self::LOG_FAILURE_MESSAGE);

            return null;
        }

        if (!$response->successful()) {
            RoutePathService::logFailure(
                ['reason' => 'http_error', 'status' => $response->status()] + RoutePathService::serviceError($response),
                self::LOG_FAILURE_MESSAGE
            );

            return null;
        }

        $features = $response->json('features');

        if (!is_array($features)) {
            RoutePathService::logFailure(['reason' => 'invalid_body', 'status' => $response->status()], self::LOG_FAILURE_MESSAGE);

            return null;
        }

        return $features;
    }

    private static function firstFeatureInCity(array $features, string $city, string $state, array $acceptedStatuses): ?array
    {
        foreach ($features as $feature) {
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $status = self::LAYER_STATUSES[$properties['layer'] ?? ''] ?? null;

            if (in_array($status, $acceptedStatuses, true)
                && self::sameName(self::firstText($properties, self::CITY_PROPERTIES), $city)
                && self::sameName(self::firstText($properties, [self::STATE_PROPERTY]), $state)) {
                return $feature;
            }
        }

        return null;
    }

    private static function sameName(?string $found, string $expected): bool
    {
        return $found !== null && self::comparableName($found) === self::comparableName($expected);
    }

    private static function comparableName(string $name): string
    {
        return Str::squish(Str::lower(Str::ascii($name)));
    }

    private function query(array $address): array
    {
        $query = self::searchFields($address) + ['size' => self::RESULT_COUNT];
        $focus = $this->focusPoint();

        if ($focus !== null) {
            $query['focus.point.lat'] = $focus[0];
            $query['focus.point.lon'] = $focus[1];
        }

        return $query;
    }

    private function focusPoint(): ?array
    {
        $view = $this->view ?? TransportMap::defaultView();

        if ($view['center'] !== null) {
            return $view['center'];
        }

        if ($view['bounds'] === null) {
            return null;
        }

        [[$south, $west], [$north, $east]] = $view['bounds'];

        return [($south + $north) / 2, ($west + $east) / 2];
    }

    private function result(array $feature): array
    {
        $properties = $feature['properties'];
        $status = self::LAYER_STATUSES[$properties['layer']];
        $position = self::position($feature['geometry']['coordinates'] ?? null);

        if ($position === null) {
            RoutePathService::logFailure(['reason' => 'invalid_body', 'status' => 200], self::LOG_FAILURE_MESSAGE);

            return ['status' => self::STATUS_UNAVAILABLE];
        }

        $result = ['status' => $status, 'label' => self::label($status, $properties)] + $position;

        if ($status === self::STATUS_NEIGHBOURHOOD || $status === self::STATUS_CITY) {
            $result['bounds'] = self::bounds($feature['bbox'] ?? null);
        }

        return $result;
    }

    private static function label(string $status, array $properties): string
    {
        $city = self::firstText($properties, self::CITY_PROPERTIES);
        $street = self::firstText($properties, ['street', 'name']);
        $neighbourhood = self::firstText($properties, ['neighbourhood']);

        $parts = match ($status) {
            self::STATUS_ADDRESS => [$street, self::firstText($properties, ['housenumber']), $neighbourhood, $city],
            self::STATUS_STREET => [$street, $neighbourhood, $city],
            self::STATUS_NEIGHBOURHOOD => [self::firstText($properties, ['name', 'neighbourhood']), $city],
            default => [$city],
        };

        return implode(self::LABEL_SEPARATOR, self::filledParts($parts));
    }

    private static function firstText(array $properties, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $properties[$name] ?? null;

            if ((is_string($value) || is_numeric($value)) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private static function filledParts(array $parts): array
    {
        return array_values(array_filter(
            array_map(fn ($part) => is_string($part) || is_numeric($part) ? trim((string) $part) : '', $parts),
            fn (string $part) => $part !== ''
        ));
    }

    private static function position($coordinates): ?array
    {
        if (!is_array($coordinates) || !array_is_list($coordinates) || count($coordinates) < 2) {
            return null;
        }

        $latitude = clsModulesPontoTransporteEscolar::normalizedCoordinate($coordinates[1], clsModulesPontoTransporteEscolar::LATITUDE_LIMIT);
        $longitude = clsModulesPontoTransporteEscolar::normalizedCoordinate($coordinates[0], clsModulesPontoTransporteEscolar::LONGITUDE_LIMIT);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }

    private static function bounds($bbox): ?array
    {
        if (!is_array($bbox) || !array_is_list($bbox) || count($bbox) !== 4) {
            return null;
        }

        $south = self::position([$bbox[0], $bbox[1]]);
        $north = self::position([$bbox[2], $bbox[3]]);

        if ($south === null || $north === null) {
            return null;
        }

        return [
            [(float) $south['latitude'], (float) $south['longitude']],
            [(float) $north['latitude'], (float) $north['longitude']],
        ];
    }
}
