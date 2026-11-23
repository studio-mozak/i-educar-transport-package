<?php

namespace iEducar\Packages\Transport\Database;

use iEducar\Packages\Transport\Models\TransportUser;
use RuntimeException;

class TransportUserYearDuplicates
{
    public const UNIQUE_INDEX = 'pessoa_transporte_ref_idpes_ano_unique';

    public static function abortWhenAny(): void
    {
        $duplicates = TransportUser::query()
            ->select('ref_idpes', 'ano')
            ->selectRaw("string_agg(cod_pessoa_transporte::text, ', ' ORDER BY cod_pessoa_transporte) AS links")
            ->groupBy('ref_idpes', 'ano')
            ->havingRaw('count(*) > 1')
            ->orderBy('ref_idpes')
            ->orderBy('ano')
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $lines = $duplicates->map(
            fn (TransportUser $duplicate) => "pessoa {$duplicate->ref_idpes}, ano {$duplicate->ano}: vínculos {$duplicate->links}"
        );

        throw new RuntimeException(
            'Existem pessoas com mais de um vínculo de transporte no mesmo ano. '
            . 'Resolva os vínculos abaixo em Usuários de transporte e rode a migration de novo. '
            . 'Nenhum dado foi alterado. ' . $lines->implode('; ')
        );
    }
}
