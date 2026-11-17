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

        return self::leafletAsset('leaflet.css') . <<<CSS
.transport-map { isolation: isolate; width: 100%; border: 1px solid #cddce6; border-radius: 3px; }
.leaflet-container .leaflet-control a { padding: 0; }
.transport-marker { border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, .5); }
.route-path-alert { margin: 8px 0; border-radius: 3px; padding: 10px 12px; font-size: 14px; overflow-wrap: anywhere; }
.route-path-alert-notice { color: #67664f; background-color: #fffee0; }
.route-path-alert-error { color: {$errorColor}; background-color: #ffeeee; }
.route-path-alert-neutral { color: rgba(0, 0, 0, .75); background-color: lightgray; }
.route-path-alert a { color: inherit; text-decoration: underline; }

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
