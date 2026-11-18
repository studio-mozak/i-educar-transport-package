<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

return new class extends clsCadastro {
    public $pessoa_logada;
    public $nome_url_sucesso = 'Efetuar cópia';

    public function Inicializar()
    {
        $retorno = 'Novo';

        $obj_permissoes = new clsPermissoes();
        $obj_permissoes->permissao_cadastra(950, $this->pessoa_logada, 7);

        $this->breadcrumb('Cópia de rotas', [
        url('intranet/educar_transporte_escolar_index.php') => 'Transporte escolar',
    ]);

        return $retorno;
    }

    public function Gerar()
    {
        $this->_titulo = 'Cópia de - rotas';
        $empresas = [ '' => 'Selecione' ];
        $anos_origem = [ '' => 'Selecione' ];
        $objTemp = new clsModulesEmpresaTransporteEscolar();
        $objTemp->setOrderby(' nome_empresa ASC');
        $lista = $objTemp->lista();
        if (is_array($lista) && count($lista)) {
            foreach ($lista as $registro) {
                $empresas["{$registro['cod_empresa_transporte_escolar']}"] = "{$registro['nome_empresa']}";
            }
        } else {
            $empresas = [ '' => 'Sem empresas cadastradas' ];
        }
        $obj_rota = new clsModulesRotaTransporteEscolar();
        $obj_rota->setOrderby(' descricao ASC');
        $obj_rota->setLimite($this->limite, $this->offset);

        $lista = $obj_rota->lista(
            null,
            $this->descricao,
            null,
            $this->nome_destino,
            $this->ano,
            null
        );
        if (is_array($lista) && count($lista)) {
            foreach ($lista as $registro) {
                $anos_origem["{$registro['ano']}"] = "{$registro['ano']}";
            }
        } else {
            $anos_origem = [ '' => 'Não existe rotas anteriores' ];
        }
        $this->campoLista('ref_cod_empresa_transporte_escolar', 'Empresa', $empresas, $this->ref_cod_empresa_transporte_escolar, '', false, '', '', false, false);
        $this->campoLista('ano_orig', 'Ano de origem', $anos_origem, $this->ano_orig, '', false, '', '', false, false);
        $this->campoNumero('ano_dest', 'Ano de destino', $this->ano_dest, 4, 5);
    }

    public function Novo()
    {
        if (!self::isDigits($this->ref_cod_empresa_transporte_escolar) || !self::isDigits($this->ano_orig) || !self::isDigits($this->ano_dest)) {
            $this->mensagem = 'Preencha os dados corretamente.<br>';

            return false;
        }

        $sourceRoutes = $this->companyRoutes($this->ano_orig);

        if ($sourceRoutes === []) {
            $this->mensagem ="Não existe rotas em $this->ano_orig para essa empresa";

            return false;
        }

        $existingNames = array_map(
            fn (array $route) => self::comparableName($route['descricao']),
            $this->companyRoutes($this->ano_dest)
        );

        $copied = 0;
        $skipped = 0;

        foreach ($sourceRoutes as $route) {
            if (in_array(self::comparableName($route['descricao']), $existingNames, true)) {
                $skipped++;

                continue;
            }

            try {
                DB::transaction(fn () => $this->copyRoute($route));
            } catch (QueryException|RuntimeException) {
                $this->mensagem = $this->interruptedCopyMessage($route['descricao'], $copied);

                return false;
            }

            $copied++;
        }

        $this->mensagem = $this->copyResultMessage($copied, $skipped);

        return $copied > 0;
    }

    private static function isDigits($value): bool
    {
        return is_scalar($value) && ctype_digit((string) $value);
    }

    private static function comparableName($name): string
    {
        return mb_strtoupper(trim((string) $name));
    }

    private function companyRoutes($year): array
    {
        $routes = new clsModulesRotaTransporteEscolar;
        $routes->setOrderby(' descricao ASC');

        return $routes->lista(null, null, null, null, $year, $this->ref_cod_empresa_transporte_escolar) ?: [];
    }

    private function copyRoute(array $source): void
    {
        $sourceRoute = (int) $source['cod_rota_transporte_escolar'];

        $route = DB::table('modules.rota_transporte_escolar')->insertGetId([
            'ref_idpes_destino' => $source['ref_idpes_destino'],
            'descricao' => $source['descricao'],
            'ano' => (int) $this->ano_dest,
            'tipo_rota' => $source['tipo_rota'],
            'km_pav' => $source['km_pav'],
            'km_npav' => $source['km_npav'],
            'ref_cod_empresa_transporte_escolar' => $this->ref_cod_empresa_transporte_escolar,
            'tercerizado' => $source['tercerizado'],
            'abrangencia' => $source['abrangencia'],
            'dias_semana' => $source['dias_semana'],
        ], 'cod_rota_transporte_escolar');

        (new clsModulesRotaTransporteEscolar($route))->copyDistancesAndPathFrom($sourceRoute);

        $this->copyItinerary($sourceRoute, $route);
    }

    private function copyItinerary(int $sourceRoute, int $route): void
    {
        $stops = (new clsModulesItinerarioTransporteEscolar)->lista(null, $sourceRoute) ?: [];

        foreach ($stops as $stop) {
            $copiedStop = new clsModulesItinerarioTransporteEscolar(
                null,
                $route,
                $stop['seq'],
                $stop['ref_cod_ponto_transporte_escolar'],
                $stop['ref_cod_veiculo'],
                $stop['hora'],
                $stop['tipo']
            );

            if (!$copiedStop->cadastra()) {
                throw new RuntimeException('Itinerary stop not copied.');
            }
        }
    }

    private function interruptedCopyMessage($routeName, int $copied): string
    {
        $failure = 'Não foi possível copiar a rota "' . e((string) $routeName) . '".';

        if ($copied === 0) {
            return "{$failure} Nenhuma rota copiada; tente de novo.";
        }

        return "{$failure} " . self::copiedRoutes($copied) . ' antes do erro; copie de novo para completar.';
    }

    private static function copiedRoutes(int $copied): string
    {
        return $copied === 1 ? '1 rota copiada' : "{$copied} rotas copiadas";
    }

    private function copyResultMessage(int $copied, int $skipped): string
    {
        $year = (int) $this->ano_dest;

        if ($copied === 0) {
            return $skipped === 1
                ? "Nenhuma rota copiada: a rota já existia em {$year}."
                : "Nenhuma rota copiada: todas já existiam em {$year}.";
        }

        $message = self::copiedRoutes($copied) . '.';

        if ($skipped === 1) {
            return $message . " 1 já existia em {$year} e não foi copiada.";
        }

        if ($skipped > 1) {
            return $message . " {$skipped} já existiam em {$year} e não foram copiadas.";
        }

        return $message;
    }

    public function Formular()
    {
        $this->title = 'Cópia de Rotas';
        $this->processoAp = '21240';
    }
};
