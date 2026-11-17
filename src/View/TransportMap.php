<?php

namespace iEducar\Packages\Transport\View;

use clsModulesPontoTransporteEscolar;
use iEducar\Packages\Transport\Services\RoutePathService;

class TransportMap
{
    public const LEAFLET_DIRECTORY = __DIR__ . '/../../ieducar/intranet/scripts/leaflet';

    public const SCRIPT = __DIR__ . '/../../ieducar/intranet/scripts/transporte-mapa.js';

    public const DEFAULT_LATITUDE_SETTING = 'transport.map_default_latitude';

    public const DEFAULT_LONGITUDE_SETTING = 'transport.map_default_longitude';

    public const ERROR_COLOR = '#c0392b';

    public const LINK_COLOR = '#1f6fb2';

    private const JSON_FOR_SCRIPT = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

    public static function script(string $dataName, array $data, string $pageScript): string
    {
        return self::leafletAsset('leaflet.js')
            . file_get_contents(self::SCRIPT) . "\n"
            . 'window.' . $dataName . ' = ' . json_encode($data, self::JSON_FOR_SCRIPT) . ";\n"
            . file_get_contents($pageScript);
    }

    public static function css(string $pageCss): string
    {
        $errorColor = self::ERROR_COLOR;
        $linkColor = self::LINK_COLOR;

        return self::leafletAsset('leaflet.css') . <<<CSS
.transport-map { isolation: isolate; width: 100%; border: 1px solid #cddce6; border-radius: 3px; }
.leaflet-container .leaflet-control a { padding: 0; }
.transport-marker { border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, .5); }
.route-path-alert { margin: 8px 0; border-radius: 3px; padding: 10px 12px; font-size: 14px; overflow-wrap: anywhere; }
.route-path-alert-notice { color: #67664f; background-color: #fffee0; }
.route-path-alert-error { color: {$errorColor}; background-color: #ffeeee; }
.route-path-alert-neutral { color: rgba(0, 0, 0, .75); background-color: lightgray; }
.route-path-alert a { color: inherit; text-decoration: underline; }
.route-path-note { margin: 8px 0; color: #555; }
.transport-map-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 8px; }
.transport-map-actions[hidden], .transport-map-actions [hidden] { display: none; }
.transport-map-actions .botaolistagem { margin: 0; }
.botaolistagem.transport-button { width: auto; min-width: 160px; white-space: nowrap; }
.botaolistagem.transport-button:disabled, .botaolistagem.transport-button[aria-disabled="true"] { background-color: #ccdce6; color: #47728f; border: 1px solid #cddce6; opacity: .6; cursor: not-allowed; }
.botaolistagem.transport-button[aria-busy="true"] { cursor: progress; }
.transport-link-button { background: none; border: 0; padding: 4px 0; color: {$linkColor}; text-decoration: underline; font-size: 14px; cursor: pointer; }
.transport-button:focus-visible, .transport-link-button:focus-visible { outline: 2px solid {$linkColor}; outline-offset: 2px; }
@media (max-width: 767px) {
  .transport-map-actions { flex-direction: column; align-items: flex-start; gap: 8px; }
  .transport-map-actions .transport-button { width: 100%; }
}

CSS . $pageCss;
    }

    public static function title(string $text): string
    {
        return '<span class="form"><b>' . e($text) . '</b></span>';
    }

    public static function defaultView(RoutePathService $service = new RoutePathService): array
    {
        return ['bounds' => $service->stopsBounds(), 'center' => self::defaultCenter()];
    }

    public static function defaultCenter(): ?array
    {
        $center = clsModulesPontoTransporteEscolar::coordinatesFromInput(
            config(self::DEFAULT_LATITUDE_SETTING),
            config(self::DEFAULT_LONGITUDE_SETTING)
        );

        if ($center['latitude'] === null) {
            return null;
        }

        return [(float) $center['latitude'], (float) $center['longitude']];
    }

    private static function leafletAsset(string $file): string
    {
        $path = self::LEAFLET_DIRECTORY . '/' . $file;

        return is_file($path) ? file_get_contents($path) . "\n" : '';
    }
}
