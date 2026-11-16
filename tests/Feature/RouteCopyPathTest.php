<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use clsModulesRotaTransporteEscolar;
use Database\Factories\LegacyUserFactory;
use iEducar\Packages\Transport\Services\RoutePathService;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';

class RouteCopyPathTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions;

    public const COPY_PAGE = '/intranet/transporte_copia_rotas.php';

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
    }

    public function test_route_copy_takes_the_distances_and_the_calculated_path(): void
    {
        $company = $this->companyRecord();
        $calculated = $this->routeRecord('Rota da Gameleira ENS009', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
        $farm = $this->stopRecord('Fazenda ENS009', ['latitude' => '-5.2001', 'longitude' => '-42.9001']);
        $school = $this->stopRecord('Escola ENS009', ['latitude' => '-5.0912', 'longitude' => '-42.8011']);
        $this->itineraryStopRecord($calculated, 1, $farm, null, '06:30', 'I');
        $this->itineraryStopRecord($calculated, 2, $school, null, '07:00', 'I');
        $this->itineraryStopRecord($calculated, 3, $farm, null, '11:30', 'V');
        $path = [
            'km_ida' => 18.4,
            'km_volta' => 18.9,
            'km_pav' => 29.1,
            'km_npav' => 8.2,
            'trajeto_ida' => '{"type":"LineString","coordinates":[[-42.9,-5.2],[-42.8,-5.09]]}',
            'trajeto_volta' => '{"type":"LineString","coordinates":[[-42.8,-5.09],[-42.9,-5.2]]}',
            'trajeto_hash' => RoutePathService::hash((new RoutePathService)->savedStops($calculated)),
            'trajeto_calculado_em' => '2026-11-12 15:40:00',
        ];
        (new clsModulesRotaTransporteEscolar($calculated))->updateDistancesAndPath($path);
        $empty = $this->routeRecord('Rota antiga ENS009', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
        $this->itineraryStopRecord($empty, 1, $school, null, '07:00', 'I');

        $this->copyRoutes($company);

        $copies = DB::table('modules.rota_transporte_escolar')
            ->where('ref_cod_empresa_transporte_escolar', $company)
            ->where('ano', 2027)
            ->get(['cod_rota_transporte_escolar', 'descricao', ...clsModulesRotaTransporteEscolar::DISTANCE_AND_PATH_COLUMNS])
            ->keyBy('descricao');

        $this->assertCount(2, $copies);

        $copy = $copies['Rota da Gameleira ENS009'];
        $this->assertSame(18.4, (float) $copy->km_ida);
        $this->assertSame(18.9, (float) $copy->km_volta);
        $this->assertSame(29.1, (float) $copy->km_pav);
        $this->assertSame(8.2, (float) $copy->km_npav);
        $this->assertSame(json_decode($path['trajeto_ida'], true), json_decode($copy->trajeto_ida, true));
        $this->assertSame(json_decode($path['trajeto_volta'], true), json_decode($copy->trajeto_volta, true));
        $this->assertSame($path['trajeto_hash'], $copy->trajeto_hash);
        $this->assertSame($path['trajeto_calculado_em'], $copy->trajeto_calculado_em);
        $this->assertSame(
            $copy->trajeto_hash,
            RoutePathService::hash((new RoutePathService)->savedStops($copy->cod_rota_transporte_escolar)),
            'the copied itinerary keeps the path up to date'
        );

        $old = $copies['Rota antiga ENS009'];

        foreach (clsModulesRotaTransporteEscolar::DISTANCE_AND_PATH_COLUMNS as $column) {
            $this->assertNull($old->{$column}, $column);
        }
    }

    private function copyRoutes(int $company): void
    {
        $this->actingAs(LegacyUserFactory::new()->admin()->create())
            ->post(self::COPY_PAGE, [
                'tipoacao' => 'Novo',
                'ref_cod_empresa_transporte_escolar' => (string) $company,
                'ano_orig' => 2026,
                'ano_dest' => 2027,
            ])
            ->assertSuccessful();
    }
}
