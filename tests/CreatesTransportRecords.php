<?php

namespace iEducar\Packages\Transport\Tests;

use Database\Factories\LegacyIndividualFactory;
use Database\Factories\LegacyOrganizationFactory;
use Illuminate\Support\Facades\DB;

trait CreatesTransportRecords
{
    protected function skipWhenVehicleControllerIsTaken(string $expectedParent): void
    {
        if (class_exists('VeiculoController', false) && !is_subclass_of('VeiculoController', $expectedParent)) {
            $this->markTestSkipped(
                'VeiculoController (' . get_parent_class('VeiculoController') . ') was already loaded in this process; '
                . 'the transport package declares two global VeiculoController classes. Run this test file on its own.'
            );
        }
    }

    protected function transportCompanyRecord(): int
    {
        return DB::table('modules.empresa_transporte_escolar')->insertGetId([
            'ref_idpes' => LegacyOrganizationFactory::new()->create()->getKey(),
            'ref_resp_idpes' => LegacyIndividualFactory::new()->create()->getKey(),
        ], 'cod_empresa_transporte_escolar');
    }

    protected function vehicleTypeId(): int
    {
        return DB::table('modules.tipo_veiculo')->orderBy('cod_tipo_veiculo')->value('cod_tipo_veiculo');
    }

    protected function vehicleRecord(array $attributes = []): int
    {
        return DB::table('modules.veiculo')->insertGetId(array_merge([
            'descricao' => 'Ônibus de teste',
            'renavam' => '12345678901',
            'passageiros' => 40,
            'malha' => 'R',
            'ref_cod_tipo_veiculo' => $this->vehicleTypeId(),
            'exclusivo_transporte_escolar' => 'S',
            'adaptado_necessidades_especiais' => 'N',
            'ativo' => 'S',
            'ref_cod_empresa_transporte_escolar' => $this->transportCompanyRecord(),
        ], $attributes), 'cod_veiculo');
    }

    protected function transportRouteRecord(string $name, int $year): int
    {
        return DB::table('modules.rota_transporte_escolar')->insertGetId([
            'ref_idpes_destino' => LegacyOrganizationFactory::new()->create()->getKey(),
            'descricao' => $name,
            'ano' => $year,
            'tipo_rota' => 'U',
            'tercerizado' => 'N',
        ], 'cod_rota_transporte_escolar');
    }

    protected function itineraryStopRecord(int $route, int $vehicle, string $direction, int $sequence): void
    {
        $stop = DB::table('modules.ponto_transporte_escolar')->insertGetId([
            'descricao' => 'Ponto ' . $sequence,
        ], 'cod_ponto_transporte_escolar');

        DB::table('modules.itinerario_transporte_escolar')->insert([
            'ref_cod_rota_transporte_escolar' => $route,
            'seq' => $sequence,
            'ref_cod_ponto_transporte_escolar' => $stop,
            'ref_cod_veiculo' => $vehicle,
            'tipo' => $direction,
        ]);
    }
}
