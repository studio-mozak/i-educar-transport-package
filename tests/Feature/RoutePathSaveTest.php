<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';

class RoutePathSaveTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions;

    public const OUTBOUND_PATH = '{"type":"LineString","coordinates":[[-42.9001,-5.2001],[-42.8011,-5.0912]]}';

    public const RETURN_PATH = '{"type":"LineString","coordinates":[[-42.8011,-5.0912],[-42.9001,-5.2001]]}';

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
    }

    public function test_it_saves_the_calculated_path_and_the_distances_with_the_itinerary(): void
    {
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026);
        [$farm, $school] = $this->stops();
        $hash = str_repeat('b', 64);

        $this->saveItinerary($route, array_merge($this->itineraryInput($farm, $school), [
            'abrangencia' => 'M',
            'km_ida' => '18,40',
            'km_volta' => '18,90',
            'km_pav' => '1.029,10',
            'km_npav' => '8,20',
            'trajeto_ida' => self::OUTBOUND_PATH,
            'trajeto_volta' => self::RETURN_PATH,
            'trajeto_hash' => $hash,
        ]))->assertRedirect('transporte_rota_det.php?cod_rota=' . $route);

        $saved = $this->route($route);
        $this->assertSame('M', $saved->abrangencia);
        $this->assertSame(18.4, (float) $saved->km_ida);
        $this->assertSame(18.9, (float) $saved->km_volta);
        $this->assertSame(1029.1, (float) $saved->km_pav);
        $this->assertSame(8.2, (float) $saved->km_npav);
        $this->assertSame(json_decode(self::OUTBOUND_PATH, true), json_decode($saved->trajeto_ida, true));
        $this->assertSame(json_decode(self::RETURN_PATH, true), json_decode($saved->trajeto_volta, true));
        $this->assertSame($hash, $saved->trajeto_hash);
        $this->assertNotNull($saved->trajeto_calculado_em);
        $this->assertCount(2, $this->itinerary($route));
    }

    public function test_a_form_without_the_new_fields_keeps_the_saved_distances_and_path(): void
    {
        $route = $this->routeWithSavedPath();
        $before = $this->route($route);

        $this->saveItinerary($route, ['abrangencia' => 'I'])
            ->assertRedirect('transporte_rota_det.php?cod_rota=' . $route);

        $saved = $this->route($route);
        $this->assertSame('I', $saved->abrangencia);
        $this->assertEquals($this->pathColumns($before), $this->pathColumns($saved));
    }

    public function test_typed_distances_without_a_new_calculation_keep_the_saved_path(): void
    {
        $route = $this->routeWithSavedPath();
        $before = $this->route($route);

        $this->saveItinerary($route, [
            'km_ida' => '20,00',
            'km_volta' => '',
            'km_pav' => '15,50',
            'km_npav' => '',
            'trajeto_ida' => '',
            'trajeto_volta' => '',
            'trajeto_hash' => '',
        ])->assertRedirect('transporte_rota_det.php?cod_rota=' . $route);

        $saved = $this->route($route);
        $this->assertSame(20.0, (float) $saved->km_ida);
        $this->assertNull($saved->km_volta);
        $this->assertSame(15.5, (float) $saved->km_pav);
        $this->assertNull($saved->km_npav);
        $this->assertSame($before->trajeto_hash, $saved->trajeto_hash);
        $this->assertSame($before->trajeto_ida, $saved->trajeto_ida);
        $this->assertSame($before->trajeto_calculado_em, $saved->trajeto_calculado_em);
    }

    public static function invalidInputs(): array
    {
        return [
            'distance with letters' => [['km_ida' => '18km']],
            'negative distance' => [['km_pav' => '-3,00']],
            'distances as list' => [['km_npav' => ['1']]],
            'path without hash format' => [['trajeto_ida' => self::OUTBOUND_PATH, 'trajeto_hash' => 'abc']],
            'path that is not a line' => [['trajeto_ida' => '{"type":"Point","coordinates":[1,2]}', 'trajeto_hash' => str_repeat('c', 64)]],
            'path with script' => [['trajeto_volta' => '<script>alert(1)</script>', 'trajeto_hash' => str_repeat('c', 64)]],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_distances_or_path_reject_the_whole_form(array $input): void
    {
        $route = $this->routeWithSavedPath();
        $before = $this->route($route);
        $itineraryBefore = $this->itinerary($route);
        [$farm, $school] = $this->stops();

        $this->saveItinerary($route, array_merge($this->itineraryInput($school, $farm), ['abrangencia' => 'I'], $input))
            ->assertSuccessful()
            ->assertSee('<time>', false)
            ->assertSee('Quilometragem ou trajeto inválidos.<br></div>', false)
            ->assertSee('name="km_ida" id="km_ida" value="18,40"', false)
            ->assertSee("name='trajeto_hash' id='trajeto_hash' type='hidden' value=''", false);

        $saved = $this->route($route);
        $this->assertSame('M', $saved->abrangencia);
        $this->assertEquals($this->pathColumns($before), $this->pathColumns($saved));
        $this->assertSame($itineraryBefore, $this->itinerary($route));
    }

    private function stops(): array
    {
        return [
            $this->stopRecord('Fazenda ENS009', ['latitude' => '-5.2001', 'longitude' => '-42.9001']),
            $this->stopRecord('Escola ENS009', ['latitude' => '-5.0912', 'longitude' => '-42.8011']),
        ];
    }

    private function itineraryInput(int $first, int $second): array
    {
        return [
            'ref_cod_ponto_transporte_escolar' => [$first . ' - Primeiro ponto ENS009', $second . ' - Segundo ponto ENS009'],
            'hora' => ['06:30', '11:30'],
            'tipo' => ['I', 'V'],
            'ref_cod_veiculo' => ['', ''],
        ];
    }

    private function routeWithSavedPath(): int
    {
        $route = $this->routeRecord('Rota da Gameleira ENS009', 2026, [
            'abrangencia' => 'M',
            'km_ida' => 18.4,
            'km_volta' => 18.9,
            'km_pav' => 29.1,
            'km_npav' => 8.2,
            'trajeto_ida' => self::OUTBOUND_PATH,
            'trajeto_volta' => self::RETURN_PATH,
            'trajeto_hash' => str_repeat('d', 64),
            'trajeto_calculado_em' => '2026-11-12 15:40:00',
        ]);
        [$farm, $school] = $this->stops();
        $this->itineraryStopRecord($route, 1, $farm, null, '06:30', 'I');
        $this->itineraryStopRecord($route, 2, $school, null, '11:30', 'V');

        return $route;
    }

    private function pathColumns(object $route): array
    {
        return array_intersect_key((array) $route, array_flip(\clsModulesRotaTransporteEscolar::DISTANCE_AND_PATH_COLUMNS));
    }
}
