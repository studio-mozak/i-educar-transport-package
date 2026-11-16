<?php

namespace iEducar\Packages\Transport\Tests;

use Database\Factories\LegacyIndividualFactory;
use Database\Factories\LegacyOrganizationFactory;
use Illuminate\Support\Facades\DB;

trait CreatesTransportUserRecords
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

    protected function personRecord(): int
    {
        return LegacyIndividualFactory::new()->create()->getKey();
    }

    protected function destinationRecord(): int
    {
        return LegacyOrganizationFactory::new()->create()->getKey();
    }

    protected function companyRecord(): int
    {
        return DB::table('modules.empresa_transporte_escolar')->insertGetId([
            'ref_idpes' => $this->destinationRecord(),
            'ref_resp_idpes' => $this->personRecord(),
        ], 'cod_empresa_transporte_escolar');
    }

    protected function routeRecord(string $name, int $year, array $attributes = []): int
    {
        return DB::table('modules.rota_transporte_escolar')->insertGetId(array_merge([
            'ref_idpes_destino' => $this->destinationRecord(),
            'descricao' => $name,
            'ano' => $year,
            'tipo_rota' => 'U',
            'tercerizado' => 'N',
        ], $attributes), 'cod_rota_transporte_escolar');
    }

    protected function stopRecord(string $name): int
    {
        return DB::table('modules.ponto_transporte_escolar')->insertGetId([
            'descricao' => $name,
        ], 'cod_ponto_transporte_escolar');
    }

    protected function transportUserRecord(int $person, int $route, array $attributes = []): int
    {
        return DB::table('modules.pessoa_transporte')->insertGetId(array_merge([
            'ref_idpes' => $person,
            'ref_cod_rota_transporte_escolar' => $route,
            'ano' => DB::table('modules.rota_transporte_escolar')->where('cod_rota_transporte_escolar', $route)->value('ano'),
        ], $attributes), 'cod_pessoa_transporte');
    }

    protected function transportUser(int $id): object
    {
        return DB::table('modules.pessoa_transporte')->where('cod_pessoa_transporte', $id)->first();
    }

    protected function transportUserCount(int $person): int
    {
        return DB::table('modules.pessoa_transporte')->where('ref_idpes', $person)->count();
    }
}
