<?php

namespace iEducar\Packages\Transport\View;

use clsModulesPontoTransporteEscolar;
use iEducar\Packages\Transport\Services\RoutePathService;

class StopLocationMap
{
    public const SCRIPT = __DIR__ . '/../../ieducar/intranet/scripts/transporte-ponto.js';

    public const TITLE = 'Localização no mapa';

    public const HELP = 'Clique no mapa ou arraste o marcador para marcar o ponto. Se preferir, digite a latitude e a longitude acima.';

    public const MAP_LABEL = 'Mapa para marcar a localização do ponto';

    public const MISSING_MESSAGE = 'Ponto sem localização. Clique no mapa para marcar.';

    public const REMOVAL_MESSAGE = 'A localização será removida ao gravar.';

    public const MARKED_MESSAGE = 'Localização marcada: {latitude}, {longitude}.';

    public const MARKER_TITLE = 'Localização do ponto';

    public const LATITUDE_PLACEHOLDER = 'Ex.: -5.091234';

    public const LONGITUDE_PLACEHOLDER = 'Ex.: -42.837512';

    public const STREET_ZOOM = 17;

    public const BOUNDS_MAX_ZOOM = 15;

    public function __construct(private readonly RoutePathService $service = new RoutePathService) {}

    public static function coordinateFieldOptions(string $label, string $placeholder, int $limit): array
    {
        return [
            'label' => $label,
            'required' => false,
            'label_hint' => 'De -' . $limit . ' a ' . $limit,
            'autocomplete' => 'off',
            'placeholder' => $placeholder,
        ];
    }

    public function formRow(): string
    {
        return '<tr id="tr_stop_location"><td colspan="2" class="formlttd">'
            . '<div class="stop-location">'
            . TransportMap::title(self::TITLE)
            . '<div class="stop-location-help"><sub class="modal-sub">' . e(self::HELP) . '</sub></div>'
            . '<div id="stop_location_map" class="transport-map stop-location-map" role="region" aria-label="' . e(self::MAP_LABEL) . '"></div>'
            . '<div id="stop_location_missing" class="route-path-alert route-path-alert-neutral" role="status" hidden>' . e(self::MISSING_MESSAGE) . '</div>'
            . '<div id="stop_location_removal" class="route-path-alert route-path-alert-notice" role="status" hidden>' . e(self::REMOVAL_MESSAGE) . '</div>'
            . '<div id="stop_location_status" class="sr-only" aria-live="polite"></div>'
            . '</div>'
            . '</td></tr>';
    }

    public function script(): string
    {
        return TransportMap::script('stopLocationMap', $this->payload(), self::SCRIPT);
    }

    public function css(): string
    {
        $errorColor = TransportMap::ERROR_COLOR;

        return TransportMap::css(<<<CSS
.stop-location { margin: 8px 0 12px; }
.stop-location-help { margin: 4px 0 8px; overflow-wrap: anywhere; }
.stop-location-help .modal-sub { vertical-align: top; }
.stop-location-map { height: 360px; cursor: crosshair; }
.stop-location-map.leaflet-grab { cursor: crosshair; }
.leaflet-marker-icon.stop-location-marker { border-radius: 50%; background: #1f6fb2; cursor: move; }
.transport-coordinate-error { display: block; margin-top: 4px; color: {$errorColor}; }
@media (max-width: 767px) {
  .stop-location-map { height: 300px; }
}
CSS);
    }

    private function payload(): array
    {
        return [
            'view' => TransportMap::defaultView($this->service),
            'zoom' => ['street' => self::STREET_ZOOM, 'boundsMax' => self::BOUNDS_MAX_ZOOM],
            'limits' => [
                'latitude' => clsModulesPontoTransporteEscolar::LATITUDE_LIMIT,
                'longitude' => clsModulesPontoTransporteEscolar::LONGITUDE_LIMIT,
            ],
            'decimals' => clsModulesPontoTransporteEscolar::COORDINATE_DECIMALS,
            'messages' => [
                'latitudeFormat' => clsModulesPontoTransporteEscolar::LATITUDE_FORMAT_MESSAGE,
                'longitudeFormat' => clsModulesPontoTransporteEscolar::LONGITUDE_FORMAT_MESSAGE,
                'latitudeRange' => clsModulesPontoTransporteEscolar::LATITUDE_RANGE_MESSAGE,
                'longitudeRange' => clsModulesPontoTransporteEscolar::LONGITUDE_RANGE_MESSAGE,
                'together' => clsModulesPontoTransporteEscolar::COORDINATES_TOGETHER_MESSAGE,
                'marked' => self::MARKED_MESSAGE,
                'markerTitle' => self::MARKER_TITLE,
            ],
        ];
    }
}
