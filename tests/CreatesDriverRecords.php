<?php

namespace iEducar\Packages\Transport\Tests;

use Database\Factories\LegacyIndividualFactory;
use Illuminate\Support\Facades\DB;

trait CreatesDriverRecords
{
    protected function driverRecord(string $name, int $company): int
    {
        return DB::table('modules.motorista')->insertGetId([
            'ref_idpes' => LegacyIndividualFactory::new()->withName($name)->create()->getKey(),
            'ref_cod_empresa_transporte_escolar' => $company,
        ], 'cod_motorista');
    }
}
