<?php

namespace iEducar\Packages\Transport\View;

use iEducar\Packages\Transport\Services\RoutePathService;
use Portabilis_Currency_Utils;

class RoutePathMap
{
    public const SCRIPT = __DIR__ . '/../../ieducar/intranet/scripts/transporte-itinerario-trajeto.js';

    public const ENDPOINT = '/module/Api/Rota';

    public const TITLE = 'Trajeto';

    public const MAP_LABEL = 'Mapa do trajeto da rota';

    public const LAST_CALCULATION = 'Último cálculo: ';

    public const CALCULATE_LABEL = 'Calcular trajeto';

    public const OUTBOUND_LABEL = 'Ida';

    public const RETURN_LABEL = 'Volta';

    public const OUTBOUND_COLOR = '#1f6fb2';

    public const RETURN_COLOR = '#b0561a';

    private ?object $path;

    private array $stops;

    public function __construct(
        private readonly int $route,
        private readonly bool $editable,
        private readonly RoutePathService $service = new RoutePathService
    ) {
        $this->path = $this->service->savedPath($route);
        $this->stops = $this->service->savedStops($route);
    }

    public function savedDistance(string $column): string
    {
        $distance = $this->path?->{$column};

        return $distance === null ? '' : number_format((float) $distance, 2, ',', '.');
    }

    public function directionsDistances(): ?string
    {
        if ($this->path?->km_ida === null && $this->path?->km_volta === null) {
            return null;
        }

        return self::kilometersText($this->path->km_ida) . ' / ' . self::kilometersText($this->path->km_volta);
    }

    public function html(): string
    {
        $modifier = $this->editable ? 'edit' : 'detail';

        $html = '<div class="route-path route-path-' . $modifier . '">'
            . '<div class="route-path-map-wrapper">'
            . '<div id="route_path_map" class="transport-map route-path-map" role="region" aria-label="' . self::MAP_LABEL . '"></div>'
            . '<div class="route-path-legend">'
            . '<span class="route-path-legend-item"><span class="route-path-legend-line route-path-outbound" aria-hidden="true"></span> ' . self::OUTBOUND_LABEL . '</span>'
            . '<span class="route-path-legend-item"><span class="route-path-legend-line route-path-return" aria-hidden="true"></span> ' . self::RETURN_LABEL . '</span>'
            . '</div>'
            . '</div>'
            . $this->paragraph('route_path_not_calculated', 'route-path-note', RoutePathService::NOT_CALCULATED_MESSAGE, !$this->hasSavedPath())
            . $this->alert('route_path_stale', 'notice', RoutePathService::STALE_MESSAGE, $this->isStale());

        if ($this->editable) {
            $html .= $this->editableHtml();
        }

        return $html . '</div>';
    }

    public function titledHtml(): string
    {
        return TransportMap::title(self::TITLE) . $this->html();
    }

    public function detailRow(): string
    {
        return '<div class="formlttd route-path-detail-row"><div class="route-path-detail-label">' . self::TITLE . ':</div>'
            . $this->html()
            . '</div>';
    }

    public function formRow(): string
    {
        return '<tr id="tr_route_path"><td colspan="2" class="formlttd">' . $this->titledHtml() . '</td></tr>';
    }

    public function script(): string
    {
        return TransportMap::script('routePathMap', $this->payload(), self::SCRIPT);
    }

    public function css(): string
    {
        $outboundColor = self::OUTBOUND_COLOR;
        $returnColor = self::RETURN_COLOR;

        return TransportMap::css(<<<CSS
.route-path { margin: 8px 0 12px; }
.route-path-map-wrapper { position: relative; isolation: isolate; }
.route-path-map { height: 420px; }
.route-path-detail .route-path-map { height: 320px; }
.route-path-detail-label { margin-bottom: 8px; }
.route-path-legend { position: absolute; right: 10px; bottom: 24px; z-index: 1000; background: #fff; color: #333; padding: 4px 8px; border-radius: 3px; box-shadow: 0 1px 4px rgba(0, 0, 0, .3); font-size: 12px; }
.route-path-legend-item { display: inline-block; margin-right: 10px; }
.route-path-legend-item:last-child { margin-right: 0; }
.route-path-legend-line { display: inline-block; width: 22px; height: 4px; vertical-align: middle; }
.route-path-legend-line.route-path-outbound { background: {$outboundColor}; }
.route-path-legend-line.route-path-return { background: {$returnColor}; }
.route-path-marker, .route-path-marker-group { height: 24px; line-height: 20px; color: #fff; font-size: 12px; font-weight: bold; text-align: center; }
.route-path-marker { width: 24px; border-radius: 50%; }
.route-path-marker-group { position: absolute; left: 0; top: 0; transform: translate(-50%, -50%); display: flex; border-radius: 12px; overflow: hidden; }
.route-path-marker-segment { min-width: 20px; padding: 0 4px; }
.route-path-marker-segment + .route-path-marker-segment { border-left: 1px solid #fff; }
.route-path-marker.route-path-outbound, .route-path-marker-segment.route-path-outbound { background: {$outboundColor}; }
.route-path-marker.route-path-return, .route-path-marker-segment.route-path-return { background: {$returnColor}; }
@media (max-width: 767px) {
  .route-path-map,.route-path-detail .route-path-map { height: 300px; }
  .route-path-legend { position: static; box-shadow: none; padding: 6px 0 0; }
  input#km_ida, input#km_volta, input#km_pav, input#km_npav { max-width: calc(100vw - 323px) !important; }
}
CSS);
    }

    private function editableHtml(): string
    {
        $hasKey = RoutePathService::hasKey();
        $missing = RoutePathService::missingCoordinates($this->stops);
        $isEmpty = $this->stops === [];

        $missingContent = e(RoutePathService::MISSING_COORDINATES_MESSAGE) . ' <span id="route_path_missing_list">' . $this->missingLinks($missing) . '</span>';

        $html = $this->alert('route_path_missing', 'error', $missingContent, $missing !== [], false)
            . $this->alert('route_path_failure', 'error', RoutePathService::UNAVAILABLE_MESSAGE, false);

        if (!$hasKey) {
            return $html . $this->alert('route_path_missing_key', 'neutral', RoutePathService::MISSING_KEY_MESSAGE, true);
        }

        $disabled = $isEmpty || $missing !== [];

        return $html
            . $this->paragraph('route_path_empty', 'route-path-note', RoutePathService::EMPTY_ITINERARY_MESSAGE, $isEmpty)
            . '<div class="transport-map-actions">'
            . '<button type="button" id="route_path_calculate" class="botaolistagem transport-button"' . ($disabled ? ' disabled' : '') . '>' . self::CALCULATE_LABEL . '</button>'
            . $this->paragraph('route_path_calculated_at', 'route-path-note', $this->calculatedAtText(), $this->hasSavedPath(), 'span')
            . '</div>';
    }

    private function payload(): array
    {
        return [
            'editable' => $this->editable,
            'endpoint' => self::ENDPOINT,
            'route' => $this->route,
            'stops' => $this->stops,
            'outbound' => $this->path?->trajeto_ida,
            'return' => $this->path?->trajeto_volta,
            'view' => TransportMap::defaultView($this->service),
            'reference' => $this->hasSavedPath() && !$this->isStale() ? RoutePathService::sequenceOf($this->stops) : null,
            'stopUrl' => RoutePathService::STOP_URL,
            'colors' => [RoutePathService::OUTBOUND => self::OUTBOUND_COLOR, RoutePathService::RETURN => self::RETURN_COLOR],
            'messages' => [
                'calculate' => self::CALCULATE_LABEL,
                'calculating' => 'Calculando…',
                'lastCalculation' => self::LAST_CALCULATION,
                'directions' => [RoutePathService::OUTBOUND => self::OUTBOUND_LABEL, RoutePathService::RETURN => self::RETURN_LABEL],
            ],
        ];
    }

    private function hasSavedPath(): bool
    {
        return $this->path !== null && $this->path->trajeto_hash !== null;
    }

    private function isStale(): bool
    {
        return $this->hasSavedPath() && $this->path->trajeto_hash !== RoutePathService::hash($this->stops);
    }

    private function calculatedAtText(): string
    {
        if (!$this->hasSavedPath() || $this->path->trajeto_calculado_em === null) {
            return '';
        }

        return self::LAST_CALCULATION . date('d/m/Y H:i', strtotime($this->path->trajeto_calculado_em));
    }

    public static function kilometersText($distance): string
    {
        return $distance === null ? '-' : Portabilis_Currency_Utils::moedaUsToBr((string) (float) $distance) . ' km';
    }

    private function missingLinks(array $missing): string
    {
        return implode(', ', array_map(
            fn (array $stop) => '<a href="' . e($stop['url']) . '">' . e($stop['stop'] . ' - ' . $stop['name']) . '</a>',
            $missing
        ));
    }

    private function alert(string $id, string $type, string $content, bool $visible, bool $escape = true): string
    {
        return '<div id="' . $id . '" class="route-path-alert route-path-alert-' . $type . '" role="alert"' . ($visible ? '' : ' hidden') . '>'
            . ($escape ? e($content) : $content)
            . '</div>';
    }

    private function paragraph(string $id, string $class, string $content, bool $visible, string $tag = 'p'): string
    {
        return "<{$tag} id=\"{$id}\" class=\"{$class}\"" . ($visible ? '' : ' hidden') . '>' . e($content) . "</{$tag}>";
    }
}
