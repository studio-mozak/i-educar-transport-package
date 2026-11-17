<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\View\TransportMap;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransportMapTest extends TestCase
{
    public static function centerSettings(): array
    {
        return [
            'both valid' => ['-5.0939', '-42.8369', [-5.0939, -42.8369]],
            'comma' => ['-5,0939', '-42,8369', [-5.0939, -42.8369]],
            'empty' => ['', '', null],
            'not configured' => [null, null, null],
            'invalid latitude' => ['-95', '-42.8369', null],
            'text' => ['Timon', 'MA', null],
            'only latitude' => ['-5.0939', '', null],
            'only longitude' => [null, '-42.8369', null],
        ];
    }

    #[DataProvider('centerSettings')]
    public function test_default_center_comes_from_the_two_settings(?string $latitude, ?string $longitude, ?array $expected): void
    {
        config([TransportMap::DEFAULT_LATITUDE_SETTING => $latitude, TransportMap::DEFAULT_LONGITUDE_SETTING => $longitude]);

        $this->assertSame($expected, TransportMap::defaultCenter());
    }

    public function test_map_controls_drop_the_padding_given_to_links_inside_table_cells(): void
    {
        $css = TransportMap::css('');

        $this->assertStringContainsString('.leaflet-container .leaflet-control a { padding: 0; }', $css);
        $this->assertGreaterThan(strpos($css, '.leaflet-control-zoom-in'), strpos($css, '.leaflet-container .leaflet-control a'));
    }

    public function test_the_package_has_no_fixed_city_center(): void
    {
        $files = array_merge(
            glob(__DIR__ . '/../../src/View/*.php'),
            glob(__DIR__ . '/../../ieducar/intranet/scripts/transporte-*.js'),
            glob(__DIR__ . '/../../database/migrations/2026_*.php')
        );

        foreach ($files as $file) {
            $this->assertStringNotContainsString('-5.0939', file_get_contents($file), $file);
            $this->assertStringNotContainsString('-42.8369', file_get_contents($file), $file);
        }
    }
}
