<?php

namespace iEducar\Packages\Transport\Tests;

use Database\Factories\LegacyIndividualFactory;
use Database\Factories\LegacyOrganizationFactory;
use Illuminate\Support\Facades\DB;

trait CreatesItineraryRecords
{
    protected function skipWhenControllerIsTaken(string $controller, string $expectedParent): void
    {
        if (class_exists($controller, false) && !is_subclass_of($controller, $expectedParent)) {
            $this->markTestSkipped(
                $controller . ' (' . get_parent_class($controller) . ') was already loaded in this process; '
                . 'the transport package declares two global ' . $controller . ' classes. Run this test file on its own.'
            );
        }
    }

    protected function companyRecord(): int
    {
        return DB::table('modules.empresa_transporte_escolar')->insertGetId([
            'ref_idpes' => LegacyOrganizationFactory::new()->create()->getKey(),
            'ref_resp_idpes' => LegacyIndividualFactory::new()->create()->getKey(),
        ], 'cod_empresa_transporte_escolar');
    }

    protected function routeRecord(string $name, int $year, array $attributes = []): int
    {
        return DB::table('modules.rota_transporte_escolar')->insertGetId(array_merge([
            'ref_idpes_destino' => LegacyOrganizationFactory::new()->create()->getKey(),
            'descricao' => $name,
            'ano' => $year,
            'tipo_rota' => 'U',
            'tercerizado' => 'N',
        ], $attributes), 'cod_rota_transporte_escolar');
    }

    protected function route(int $route): object
    {
        return DB::table('modules.rota_transporte_escolar')->where('cod_rota_transporte_escolar', $route)->first();
    }

    protected function stopRecord(string $name, array $attributes = []): int
    {
        return DB::table('modules.ponto_transporte_escolar')->insertGetId(array_merge([
            'descricao' => $name,
        ], $attributes), 'cod_ponto_transporte_escolar');
    }

    protected function stopCoordinates(int $stop): array
    {
        $record = DB::table('modules.ponto_transporte_escolar')->where('cod_ponto_transporte_escolar', $stop)->first(['latitude', 'longitude']);

        return [$record->latitude, $record->longitude];
    }

    protected function vehicleRecord(string $name): int
    {
        return DB::table('modules.veiculo')->insertGetId([
            'descricao' => $name,
            'renavam' => '12345678901',
            'passageiros' => 40,
            'malha' => 'R',
            'ref_cod_tipo_veiculo' => DB::table('modules.tipo_veiculo')->orderBy('cod_tipo_veiculo')->value('cod_tipo_veiculo'),
            'exclusivo_transporte_escolar' => 'S',
            'adaptado_necessidades_especiais' => 'N',
            'ativo' => 'S',
            'ref_cod_empresa_transporte_escolar' => $this->companyRecord(),
        ], 'cod_veiculo');
    }

    protected function itineraryStopRecord(int $route, int $sequence, int $stop, ?int $vehicle, string $time, string $direction): void
    {
        DB::table('modules.itinerario_transporte_escolar')->insert([
            'ref_cod_rota_transporte_escolar' => $route,
            'seq' => $sequence,
            'ref_cod_ponto_transporte_escolar' => $stop,
            'ref_cod_veiculo' => $vehicle,
            'hora' => $time,
            'tipo' => $direction,
        ]);
    }

    protected function itinerary(int $route): array
    {
        return DB::table('modules.itinerario_transporte_escolar')
            ->where('ref_cod_rota_transporte_escolar', $route)
            ->orderBy('seq')
            ->get(['seq', 'ref_cod_ponto_transporte_escolar', 'ref_cod_veiculo', 'hora', 'tipo'])
            ->map(fn ($stop) => (array) $stop)
            ->all();
    }
}
