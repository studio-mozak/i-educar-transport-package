<?php

namespace iEducar\Packages\Transport\View;

use clsModulesPontoTransporteEscolar;
use iEducar\Packages\Transport\Services\AddressLocationService;
use iEducar\Packages\Transport\Services\RoutePathService;

class StopLocationMap
{
    public const SCRIPT = __DIR__ . '/../../ieducar/intranet/scripts/transporte-ponto.js';

    public const ENDPOINT = '/module/Api/Ponto';

    public const TITLE = 'Localização no mapa';

    public const HELP = 'Clique no mapa ou arraste o marcador para marcar o ponto.';

    public const MAP_LABEL = 'Mapa para marcar a localização do ponto';

    public const MISSING_MESSAGE = 'Ponto sem localização. Clique no mapa para marcar.';

    public const REMOVAL_MESSAGE = 'A localização será removida ao gravar.';

    public const MARKED_MESSAGE = 'Localização marcada: {latitude}, {longitude}.';

    public const MARKER_TITLE = 'Localização do ponto';

    public const ADDRESS_PLACEHOLDER = '{address}';

    public const SEARCHING_MESSAGE = 'Procurando o endereço no mapa…';

    public const ADDRESS_FOUND_MESSAGE = 'Localização marcada pelo endereço {address}. Confira no mapa se o marcador está no lugar certo. Se não estiver, arraste o marcador ou clique no local exato.';

    public const STREET_FOUND_MESSAGE = 'O número não foi encontrado, então o marcador foi colocado no meio da rua {address}. Arraste o marcador até o local exato do ponto. Se não for essa rua, clique no lugar certo do mapa.';

    public const NEIGHBOURHOOD_FOUND_MESSAGE = 'O endereço foi encontrado só até o bairro {address}.';

    public const CITY_FOUND_MESSAGE = 'O endereço foi encontrado só até a cidade {address}.';

    public const REGION_HINT = 'O mapa mostra a região: clique no local exato para marcar o ponto.';

    public const NOT_FOUND_MESSAGE = 'Endereço não encontrado no mapa.';

    public const NOT_FOUND_HINT = 'Confira o endereço digitado ou clique no mapa para marcar o ponto.';

    public const UNAVAILABLE_MESSAGE = 'Não foi possível procurar o endereço no mapa agora.';

    public const UNAVAILABLE_HINT = 'Clique no mapa para marcar o ponto.';

    public const PREVIOUS_KEPT_MESSAGE = 'A localização anterior só será trocada ao gravar.';

    public const MARKER_KEPT_MESSAGE = 'O marcador não foi movido.';

    public const ADJUSTED_MESSAGE = 'Localização ajustada no mapa. Ela será salva ao clicar em Gravar.';

    public const LOCATE_LABEL = 'Localizar pelo endereço';

    public const LOCATING_LABEL = 'Localizando…';

    public const LOCATE_HINT = 'Leva o marcador ao endereço preenchido acima. A localização só muda ao gravar.';

    public const LOCATE_INCOMPLETE_HINT = 'Preencha o endereço para localizar pelo mapa.';

    public const REMOVE_LABEL = 'Remover localização';

    public const STREET_ZOOM = 17;

    public const BOUNDS_MAX_ZOOM = 15;

    public const NEIGHBOURHOOD_ZOOM = 15;

    public const CITY_ZOOM = 13;

    public function __construct(private readonly RoutePathService $service = new RoutePathService) {}

    public function formRow(): string
    {
        return '<tr id="tr_stop_location"><td colspan="2" class="formlttd">'
            . '<div class="stop-location">'
            . TransportMap::title(self::TITLE)
            . '<div class="stop-location-help"><sub class="modal-sub">' . e(self::HELP) . '</sub></div>'
            . '<div id="stop_location_map" class="transport-map stop-location-map" role="region" aria-label="' . e(self::MAP_LABEL) . '"></div>'
            . '<div id="stop_location_notice" role="status"></div>'
            . '<div id="stop_location_status" class="sr-only" aria-live="polite"></div>'
            . $this->actionsHtml()
            . '</div>'
            . '</td></tr>';
    }

    public function script(): string
    {
        return TransportMap::script('stopLocationMap', $this->payload(), self::SCRIPT);
    }

    public function css(): string
    {
        return TransportMap::css(<<<'CSS'
.stop-location { margin: 8px 0 12px; contain: inline-size; }
.stop-location-help { margin: 4px 0 8px; overflow-wrap: anywhere; }
.stop-location-help .modal-sub { vertical-align: top; }
.stop-location-map { height: 360px; cursor: crosshair; }
.stop-location-map.leaflet-grab { cursor: crosshair; }
.leaflet-marker-icon.stop-location-marker { border-radius: 50%; background: #1f6fb2; cursor: move; }
@media (max-width: 767px) {
  .stop-location-map { height: 300px; }
}
CSS);
    }

    private function actionsHtml(): string
    {
        $html = '<div id="stop_location_actions" class="transport-map-actions" hidden>';

        if (RoutePathService::hasKey()) {
            $html .= '<button type="button" id="stop_location_locate" class="botaolistagem transport-button" aria-describedby="stop_location_locate_hint" hidden>' . e(self::LOCATE_LABEL) . '</button>';
        }

        $html .= '<button type="button" id="stop_location_remove" class="transport-link-button" hidden>' . e(self::REMOVE_LABEL) . '</button>';

        if (RoutePathService::hasKey()) {
            $html .= '<sub id="stop_location_locate_hint" class="modal-sub" hidden>' . e(self::LOCATE_HINT) . '</sub>';
        }

        return $html . '</div>';
    }

    private function payload(): array
    {
        return [
            'view' => TransportMap::defaultView($this->service),
            'zoom' => [
                'street' => self::STREET_ZOOM,
                'boundsMax' => self::BOUNDS_MAX_ZOOM,
                'neighbourhood' => self::NEIGHBOURHOOD_ZOOM,
                'city' => self::CITY_ZOOM,
            ],
            'limits' => [
                'latitude' => clsModulesPontoTransporteEscolar::LATITUDE_LIMIT,
                'longitude' => clsModulesPontoTransporteEscolar::LONGITUDE_LIMIT,
            ],
            'decimals' => clsModulesPontoTransporteEscolar::COORDINATE_DECIMALS,
            'search' => [
                'enabled' => RoutePathService::hasKey(),
                'endpoint' => self::ENDPOINT,
                'resource' => AddressLocationService::API_RESOURCE,
                'statuses' => [
                    'address' => AddressLocationService::STATUS_ADDRESS,
                    'street' => AddressLocationService::STATUS_STREET,
                    'neighbourhood' => AddressLocationService::STATUS_NEIGHBOURHOOD,
                    'city' => AddressLocationService::STATUS_CITY,
                    'notFound' => AddressLocationService::STATUS_NOT_FOUND,
                ],
            ],
            'messages' => [
                'marked' => self::MARKED_MESSAGE,
                'markerTitle' => self::MARKER_TITLE,
                'missing' => self::MISSING_MESSAGE,
                'removal' => self::REMOVAL_MESSAGE,
                'addressPlaceholder' => self::ADDRESS_PLACEHOLDER,
                'searching' => self::SEARCHING_MESSAGE,
                'addressFound' => self::ADDRESS_FOUND_MESSAGE,
                'streetFound' => self::STREET_FOUND_MESSAGE,
                'neighbourhoodFound' => self::NEIGHBOURHOOD_FOUND_MESSAGE,
                'cityFound' => self::CITY_FOUND_MESSAGE,
                'regionHint' => self::REGION_HINT,
                'notFound' => self::NOT_FOUND_MESSAGE,
                'notFoundHint' => self::NOT_FOUND_HINT,
                'unavailable' => self::UNAVAILABLE_MESSAGE,
                'unavailableHint' => self::UNAVAILABLE_HINT,
                'previousKept' => self::PREVIOUS_KEPT_MESSAGE,
                'markerKept' => self::MARKER_KEPT_MESSAGE,
                'adjusted' => self::ADJUSTED_MESSAGE,
                'locate' => self::LOCATE_LABEL,
                'locating' => self::LOCATING_LABEL,
                'locateHint' => self::LOCATE_HINT,
                'locateIncompleteHint' => self::LOCATE_INCOMPLETE_HINT,
            ],
        ];
    }
}
