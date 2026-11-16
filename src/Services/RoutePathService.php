<?php

namespace iEducar\Packages\Transport\Services;

use clsModulesPontoTransporteEscolar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Portabilis_Currency_Utils;

class RoutePathService
{
    public const KEY_SETTING = 'transport.openrouteservice_key';

    public const DIRECTIONS_URL = 'https://api.openrouteservice.org/v2/directions/driving-car/geojson';

    public const GEOJSON_MEDIA_TYPE = 'application/geo+json';

    public const TIMEOUT_SECONDS = 15;

    public const LOG_FAILURE_MESSAGE = 'OpenRouteService directions request failed';

    public const LOG_MESSAGE_LIMIT = 300;

    public const REDACTED = '[redacted]';

    public const MAX_WAYPOINTS = 50;

    public const MAX_STOPS = 500;

    public const MAX_PATH_COORDINATES = 100000;

    public const MAX_DISTANCE_KM = 100000;

    public const PAVED_SURFACES = [1, 3, 4, 5, 6, 7, 14];

    public const OUTBOUND = 'I';

    public const RETURN = 'V';

    public const STATUS_CALCULATED = 'calculated';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_MISSING_KEY = 'missing_key';

    public const STATUS_MISSING_COORDINATES = 'missing_coordinates';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const MISSING_KEY_MESSAGE = 'Configure a chave do serviço de rotas em Configurações de sistema.';

    public const UNAVAILABLE_MESSAGE = 'Não foi possível calcular o trajeto agora. Tente novamente mais tarde.';

    public const MISSING_COORDINATES_MESSAGE = 'Pontos sem localização:';

    public const EMPTY_ITINERARY_MESSAGE = 'Inclua pontos no itinerário para calcular o trajeto.';

    public const STALE_MESSAGE = 'Itinerário alterado depois do último cálculo. Calcule o trajeto novamente.';

    public const NOT_CALCULATED_MESSAGE = 'Trajeto ainda não calculado.';

    public const STOP_URL = '/module/TransporteEscolar/Ponto?id=';

    private const SEQUENCE_PATTERN = '/^\d+:[IV](,\d+:[IV])*$/';

    private const DECIMAL_NUMBER_SQL_PATTERN = '^-?[0-9]+(\.[0-9]+)?$';

    public static function hasKey(): bool
    {
        return self::key() !== '';
    }

    private static function key(): string
    {
        return trim((string) config(self::KEY_SETTING));
    }

    public function savedStops(int $route): array
    {
        $stops = DB::table('modules.itinerario_transporte_escolar as itinerary')
            ->join('modules.ponto_transporte_escolar as stop', 'stop.cod_ponto_transporte_escolar', '=', 'itinerary.ref_cod_ponto_transporte_escolar')
            ->where('itinerary.ref_cod_rota_transporte_escolar', $route)
            ->orderBy('itinerary.seq')
            ->get([
                'stop.cod_ponto_transporte_escolar as stop',
                'stop.descricao as name',
                'itinerary.tipo as direction',
                'stop.latitude',
                'stop.longitude',
            ]);

        return $this->numbered($stops->map(fn ($stop) => $this->stop($stop, $stop->direction))->all());
    }

    public static function sequenceFromInput($input): ?array
    {
        if ($input === null) {
            return null;
        }

        if (!is_string($input)) {
            return [];
        }

        $input = trim($input);

        if ($input === '' || !preg_match(self::SEQUENCE_PATTERN, $input)) {
            return [];
        }

        $sequence = array_map(function (string $item) {
            [$stop, $direction] = explode(':', $item);

            return ['stop' => (int) $stop, 'direction' => $direction];
        }, explode(',', $input));

        return array_slice($sequence, 0, self::MAX_STOPS);
    }

    public static function sequenceOf(array $stops): string
    {
        return implode(',', array_map(fn (array $stop) => $stop['stop'] . ':' . $stop['direction'], $stops));
    }

    public function stopsInSequence(array $sequence): array
    {
        $ids = array_values(array_unique(array_column($sequence, 'stop')));

        if ($ids === []) {
            return [];
        }

        $records = DB::table('modules.ponto_transporte_escolar')
            ->whereIn('cod_ponto_transporte_escolar', $ids)
            ->get(['cod_ponto_transporte_escolar as stop', 'descricao as name', 'latitude', 'longitude'])
            ->keyBy('stop');

        $stops = [];

        foreach ($sequence as $item) {
            $record = $records->get($item['stop']);

            if ($record) {
                $stops[] = $this->stop($record, $item['direction']);
            }
        }

        return $this->numbered($stops);
    }

    public static function hash(array $stops): string
    {
        return hash('sha256', json_encode(array_map(
            fn (array $stop) => [$stop['stop'], $stop['direction'], $stop['latitude'], $stop['longitude']],
            $stops
        )));
    }

    public static function missingCoordinates(array $stops): array
    {
        $missing = [];

        foreach ($stops as $stop) {
            if ($stop['latitude'] === null || $stop['longitude'] === null) {
                $missing[$stop['stop']] = [
                    'stop' => $stop['stop'],
                    'name' => $stop['name'],
                    'url' => self::STOP_URL . $stop['stop'],
                ];
            }
        }

        return array_values($missing);
    }

    public function calculate(array $stops): array
    {
        if ($stops === []) {
            return $this->failure(self::STATUS_EMPTY, self::EMPTY_ITINERARY_MESSAGE);
        }

        if (!self::hasKey()) {
            return $this->failure(self::STATUS_MISSING_KEY, self::MISSING_KEY_MESSAGE);
        }

        $missing = self::missingCoordinates($stops);

        if ($missing !== []) {
            return $this->failure(self::STATUS_MISSING_COORDINATES, self::MISSING_COORDINATES_MESSAGE) + ['missing' => $missing];
        }

        $directions = [];

        foreach ([self::OUTBOUND, self::RETURN] as $direction) {
            $directionStops = array_values(array_filter($stops, fn (array $stop) => $stop['direction'] === $direction));
            $path = $this->directionPath($directionStops);

            if ($path === false) {
                return $this->failure(self::STATUS_UNAVAILABLE, self::UNAVAILABLE_MESSAGE);
            }

            $directions[$direction] = $path;
        }

        $meters = $directions[self::OUTBOUND]['meters'] + $directions[self::RETURN]['meters'];
        $pavedMeters = $directions[self::OUTBOUND]['paved'] + $directions[self::RETURN]['paved'];

        return [
            'status' => self::STATUS_CALCULATED,
            'message' => '',
            'hash' => self::hash($stops),
            'stops' => $stops,
            'outbound' => $this->directionResult($directions[self::OUTBOUND]),
            'return' => $this->directionResult($directions[self::RETURN]),
            'km_paved' => self::kilometers($pavedMeters),
            'km_unpaved' => self::kilometers(max($meters - $pavedMeters, 0)),
            'calculated_at' => now()->format('d/m/Y H:i'),
        ];
    }

    public static function distanceFromInput($value): float|null|false
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $number = Portabilis_Currency_Utils::moedaBrToUs($value);

        if (!is_numeric($number) || (float) $number < 0 || (float) $number > self::MAX_DISTANCE_KM) {
            return false;
        }

        return round((float) $number, 2);
    }

    public static function pathFromInput($outbound, $return, $hash): ?array
    {
        if (!is_string($hash) || !preg_match('/^[0-9a-f]{64}$/', $hash)) {
            return null;
        }

        $outboundPath = self::lineStringFromInput($outbound);
        $returnPath = self::lineStringFromInput($return);

        if ($outboundPath === false || $returnPath === false) {
            return null;
        }

        return [
            'trajeto_ida' => $outboundPath,
            'trajeto_volta' => $returnPath,
            'trajeto_hash' => $hash,
            'trajeto_calculado_em' => now(),
        ];
    }

    public function savedPath(int $route): ?object
    {
        $path = DB::table('modules.rota_transporte_escolar')
            ->where('cod_rota_transporte_escolar', $route)
            ->first(['km_ida', 'km_volta', 'km_pav', 'km_npav', 'trajeto_ida', 'trajeto_volta', 'trajeto_hash', 'trajeto_calculado_em']);

        if (!$path) {
            return null;
        }

        $path->trajeto_ida = $path->trajeto_ida === null ? null : json_decode($path->trajeto_ida, true);
        $path->trajeto_volta = $path->trajeto_volta === null ? null : json_decode($path->trajeto_volta, true);

        return $path;
    }

    public function stopsBounds(): ?array
    {
        $bounds = DB::table('modules.ponto_transporte_escolar')
            ->whereRaw(self::coordinateWithinLimitSql('latitude', clsModulesPontoTransporteEscolar::LATITUDE_LIMIT))
            ->whereRaw(self::coordinateWithinLimitSql('longitude', clsModulesPontoTransporteEscolar::LONGITUDE_LIMIT))
            ->selectRaw('min(latitude::float) as south, min(longitude::float) as west, max(latitude::float) as north, max(longitude::float) as east')
            ->first();

        if (!$bounds || $bounds->south === null) {
            return null;
        }

        return [[(float) $bounds->south, (float) $bounds->west], [(float) $bounds->north, (float) $bounds->east]];
    }

    private static function coordinateWithinLimitSql(string $column, int $limit): string
    {
        return sprintf(
            "case when %1\$s ~ '%2\$s' then abs(%1\$s::float) <= %3\$d else false end",
            $column,
            self::DECIMAL_NUMBER_SQL_PATTERN,
            $limit
        );
    }

    private static function lineStringFromInput($input): string|null|false
    {
        if ($input === null || $input === '') {
            return null;
        }

        $geometry = is_string($input) ? json_decode($input, true) : null;

        if (!is_array($geometry) || ($geometry['type'] ?? null) !== 'LineString' || !is_array($geometry['coordinates'] ?? null)) {
            return false;
        }

        $coordinates = $geometry['coordinates'];

        if (count($coordinates) < 2 || count($coordinates) > self::MAX_PATH_COORDINATES) {
            return false;
        }

        if (!array_is_list($coordinates)) {
            return false;
        }

        foreach ($coordinates as $index => $coordinate) {
            if (!self::isCoordinate($coordinate)) {
                return false;
            }

            $coordinates[$index] = [(float) $coordinate[0], (float) $coordinate[1]];
        }

        return json_encode(['type' => 'LineString', 'coordinates' => $coordinates]);
    }

    private static function isCoordinate($coordinate): bool
    {
        return is_array($coordinate)
            && array_is_list($coordinate)
            && count($coordinate) >= 2
            && is_numeric($coordinate[0])
            && is_numeric($coordinate[1])
            && abs((float) $coordinate[0]) <= 180
            && abs((float) $coordinate[1]) <= 90;
    }

    private function stop(object $record, ?string $direction): array
    {
        return [
            'stop' => (int) $record->stop,
            'name' => (string) $record->name,
            'direction' => $direction === self::RETURN ? self::RETURN : self::OUTBOUND,
            'latitude' => clsModulesPontoTransporteEscolar::normalizedCoordinate($record->latitude, clsModulesPontoTransporteEscolar::LATITUDE_LIMIT),
            'longitude' => clsModulesPontoTransporteEscolar::normalizedCoordinate($record->longitude, clsModulesPontoTransporteEscolar::LONGITUDE_LIMIT),
        ];
    }

    private function numbered(array $stops): array
    {
        foreach ($stops as $index => $stop) {
            $stops[$index]['number'] = $index + 1;
        }

        return $stops;
    }

    private static function kilometers(float $meters): float
    {
        return round($meters / 1000, 2);
    }

    private function failure(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }

    private function directionResult(array $path): array
    {
        return [
            'km' => $path['stops'] === 0 ? null : self::kilometers($path['meters']),
            'geometry' => count($path['coordinates']) < 2 ? null : ['type' => 'LineString', 'coordinates' => $path['coordinates']],
        ];
    }

    private function directionPath(array $stops): array|false
    {
        $path = ['stops' => count($stops), 'meters' => 0.0, 'paved' => 0.0, 'coordinates' => []];

        if (count($stops) < 2) {
            return $path;
        }

        $waypoints = array_map(fn (array $stop) => [(float) $stop['longitude'], (float) $stop['latitude']], $stops);

        for ($start = 0; $start < count($waypoints) - 1; $start += self::MAX_WAYPOINTS - 1) {
            $leg = $this->requestLeg(array_slice($waypoints, $start, self::MAX_WAYPOINTS));

            if ($leg === null) {
                return false;
            }

            $path['meters'] += $leg['meters'];
            $path['paved'] += $leg['paved'];
            $path['coordinates'] = array_merge(
                $path['coordinates'],
                $path['coordinates'] === [] ? $leg['coordinates'] : array_slice($leg['coordinates'], 1)
            );
        }

        return $path;
    }

    private function requestLeg(array $waypoints): ?array
    {
        try {
            $response = Http::withHeaders(['Authorization' => self::key()])
                ->accept(self::GEOJSON_MEDIA_TYPE)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::DIRECTIONS_URL, [
                    'coordinates' => $waypoints,
                    'extra_info' => ['surface'],
                ]);
        } catch (ConnectionException) {
            $this->logFailure(['reason' => 'connection']);

            return null;
        }

        if (!$response->successful()) {
            $this->logFailure(['reason' => 'http_error', 'status' => $response->status()] + $this->serviceError($response));

            return null;
        }

        $feature = $response->json('features.0');
        $coordinates = $feature['geometry']['coordinates'] ?? null;

        if (!is_array($feature) || !is_array($coordinates)) {
            $this->logFailure(['reason' => 'invalid_body', 'status' => $response->status()]);

            return null;
        }

        $meters = (float) ($feature['properties']['summary']['distance'] ?? 0);

        return [
            'meters' => $meters,
            'paved' => min($this->pavedMeters($feature['properties']['extras']['surface']['summary'] ?? []), $meters),
            'coordinates' => array_values(array_map(
                fn (array $coordinate) => [(float) $coordinate[0], (float) $coordinate[1]],
                array_filter($coordinates, fn ($coordinate) => self::isCoordinate($coordinate))
            )),
        ];
    }

    private function serviceError(Response $response): array
    {
        $error = $response->json('error');

        if (is_array($error)) {
            return ['code' => $error['code'] ?? null, 'message' => $this->safeMessage($error['message'] ?? null)];
        }

        return ['code' => null, 'message' => $this->safeMessage($error)];
    }

    private function safeMessage($message): ?string
    {
        if (!is_string($message) && !is_numeric($message)) {
            return null;
        }

        $message = (string) $message;
        $key = self::key();

        if ($key !== '') {
            $message = str_replace($key, self::REDACTED, $message);
        }

        return Str::limit($message, self::LOG_MESSAGE_LIMIT);
    }

    private function logFailure(array $context): void
    {
        Log::warning(self::LOG_FAILURE_MESSAGE, $context);
    }

    private function pavedMeters($surfaces): float
    {
        if (!is_array($surfaces)) {
            return 0.0;
        }

        $paved = 0.0;

        foreach ($surfaces as $surface) {
            if (is_array($surface) && in_array((int) ($surface['value'] ?? 0), self::PAVED_SURFACES, true)) {
                $paved += (float) ($surface['distance'] ?? 0);
            }
        }

        return $paved;
    }
}
