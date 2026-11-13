<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends clsDetalhe {
    public $titulo;

    public function Gerar()
    {
        // Verificação de permissão para cadastro.
        $this->obj_permissao = new clsPermissoes();

        $this->nivel_usuario = $this->obj_permissao->nivel_acesso($this->pessoa_logada);

        $this->titulo = 'Veiculo - Detalhe';

        $cod_veiculo = $_GET['cod_veiculo'];

        $tmp_obj = new clsModulesVeiculo($cod_veiculo);
        $registro = $tmp_obj->detalhe();

        if (! $registro) {
            $this->simpleRedirect('transporte_veiculo_lst.php');
        }

        $this->addDetalhe(['Código do veículo', $cod_veiculo]);
        $this->addDetalhe(['Descrição', $registro['descricao']]);
        $this->addDetalhe(['Placa', $registro['placa']]);
        $this->addDetalhe(['Renavam', $registro['renavam']]);
        if (trim($registro['data_licenca'])!='') {
            $this->addDetalhe(['Data de licença', Portabilis_Date_Utils::pgSQLToBr($registro['data_licenca']) ]);
        }
        $this->addDetalhe(['Chassi', $registro['chassi']]);
        $this->addDetalhe(['Marca', $registro['marca']]);
        $this->addDetalhe(['Modelo', trim((string) $registro['modelo']) !== '' ? e($registro['modelo']) : '-']);
        $this->addDetalhe(['Ano fabricação', $registro['ano_fabricacao']]);
        $this->addDetalhe(['Ano modelo', $registro['ano_modelo']]);
        $this->addDetalhe(['Limite de passageiros', $registro['passageiros']]);
        $malha ='';
        switch ($registro['malha']) {
      case 'A':
        $malha = 'Aquática/Embarcação';
        break;
      case 'F':
        $malha = 'Ferroviária';
        break;
      case 'R':
        $malha = 'Rodoviária';
        break;
    }
        $this->addDetalhe(['Malha', $malha]);
        $this->addDetalhe(['Categoria', $registro['descricao_tipo']]);
        $this->addDetalhe(['Exclusivo para transporte escolar', ($registro['exclusivo_transporte_escolar'] == 'S' ? 'Sim' : 'Não')]);
        $this->addDetalhe(['Adaptado para pessoas com necessidades especiais', ($registro['adaptado_necessidades_especiais'] == 'S' ? 'Sim' : 'Não')]);
        $this->addDetalhe(['Terceirizado', ($registro['terceirizado'] == 'S' ? 'Sim' : 'Não')]);
        $this->addDetalhe(['Ativo', ($registro['ativo'] == 'S' ? 'Sim' : 'Não')]);
        if ($registro['ativo']=='N') {
            $this->addDetalhe(['Descrição inativo', $registro['descricao_inativo']]);
        }
        $this->addDetalhe(['Empresa', $registro['nome_empresa']]);
        $this->addDetalhe(['Motorista responsável', $registro['nome_motorista']]);
        $this->addDetalhe(['Observação', $registro['observacao']]);
        $this->addDetalhe(['Itinerários', $this->routesTable($this->vehicleRoutes($cod_veiculo))]);
        $this->url_cancelar = 'transporte_veiculo_lst.php';

        $this->largura = '100%';

        $obj_permissao = new clsPermissoes();

        if ($obj_permissao->permissao_cadastra(21237, $this->pessoa_logada, 7, null, true)) {
            $this->url_novo = '../module/TransporteEscolar/Veiculo';
        }
        if ($obj_permissao->permissao_editar(21237, $this->pessoa_logada, 7, null, true)) {
            $this->url_editar = "../module/TransporteEscolar/Veiculo?id={$cod_veiculo}";
        }

        $this->breadcrumb('Detalhe do veículo', [
        url('intranet/educar_transporte_escolar_index.php') => 'Transporte escolar',
    ]);
    }

    private function vehicleRoutes($vehicleId): Collection
    {
        return DB::table('modules.itinerario_transporte_escolar as itinerary')
            ->join('modules.rota_transporte_escolar as route', 'route.cod_rota_transporte_escolar', '=', 'itinerary.ref_cod_rota_transporte_escolar')
            ->where('itinerary.ref_cod_veiculo', $vehicleId)
            ->distinct()
            ->orderByDesc('route.ano')
            ->orderBy('route.descricao')
            ->orderBy('route.cod_rota_transporte_escolar')
            ->orderBy('itinerary.tipo')
            ->get([
                'route.cod_rota_transporte_escolar as route_id',
                'route.ano as year',
                'route.descricao as route_name',
                'itinerary.tipo as direction',
            ]);
    }

    private function routesTable(Collection $routes): string
    {
        if ($routes->isEmpty()) {
            return 'Veículo não utilizado em nenhum itinerário.';
        }

        $rows = $routes->values()->map(function ($route, $index) {
            $color = $index % 2 === 0 ? ' bgcolor="#f5f9fd" ' : ' bgcolor="#FFFFFF" ';

            return sprintf(
                '<tr><td %1$s align=left>%2$s</td><td %1$s align=left>%3$s</td><td %1$s align=left>%4$s</td><td %1$s align=left><a style="text-decoration: underline;" href="/intranet/transporte_itinerario_cad.php?cod_rota=%5$d">Editar itinerário</a></td></tr>',
                $color,
                $route->year,
                e($route->route_name),
                $route->direction === 'V' ? 'Volta' : 'Ida',
                $route->route_id
            );
        })->implode('');

        return '<div style="width: 0; min-width: 100%; overflow-x: auto;"><table><tr align="center"><td bgcolor="#ccdce6"><b>Ano</b></td><td bgcolor="#ccdce6"><b>Rota</b></td><td bgcolor="#ccdce6"><b>Sentido</b></td><td bgcolor="#ccdce6"></td></tr>' . $rows . '</table></div>';
    }

    public function Formular()
    {
        $this->title = 'Veiculos';
        $this->processoAp = 21237;
    }
};
