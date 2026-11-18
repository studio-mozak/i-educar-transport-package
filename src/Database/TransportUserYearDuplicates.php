<?php

namespace iEducar\Packages\Transport\Database;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransportUserYearDuplicates
{
    public const UNIQUE_INDEX = 'pessoa_transporte_ref_idpes_ano_unique';

    public static function abortWhenAny(): void
    {
        $duplicates = DB::select('
            SELECT ref_idpes, ano, string_agg(cod_pessoa_transporte::text, \', \' ORDER BY cod_pessoa_transporte) AS links
            FROM modules.pessoa_transporte
            GROUP BY ref_idpes, ano
            HAVING count(*) > 1
            ORDER BY ref_idpes, ano
        ');

        if (empty($duplicates)) {
            return;
        }

        $lines = array_map(
            fn ($duplicate) => "pessoa {$duplicate->ref_idpes}, ano {$duplicate->ano}: vínculos {$duplicate->links}",
            $duplicates
        );

        throw new RuntimeException(
            'Existem pessoas com mais de um vínculo de transporte no mesmo ano. '
            . 'Resolva os vínculos abaixo em Usuários de transporte e rode a migration de novo. '
            . 'Nenhum dado foi alterado. ' . implode('; ', $lines)
        );
    }
}
