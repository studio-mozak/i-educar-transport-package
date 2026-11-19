<?php

use iEducar\Packages\Transport\View\DetailTable;
use iEducar\Packages\Transport\View\RoutePathMap;

return new class extends clsDetalhe {
    public $titulo;

    private $routePathMap;

    private function vehicleText(array $vehicle): string
    {
        $parts = [$vehicle['descricao'], 'Placa: ' . $vehicle['placa']];
        $driver = is_numeric($vehicle['ref_cod_motorista']) ? (new clsModulesMotorista($vehicle['ref_cod_motorista']))->detalhe() : false;

        if ($driver && trim((string) $driver['nome_motorista']) !== '') {
            $parts[] = 'Motorista: ' . $driver['nome_motorista'];
        }

        return implode(' - ', $parts);
    }

    public function Gerar()
    {
        // Verificação de permissão para cadastro.
        $this->obj_permissao = new clsPermissoes();

        $this->nivel_usuario = $this->obj_permissao->nivel_acesso($this->pessoa_logada);

        $this->titulo = 'Rota - Detalhe';

        $cod_rota_transporte_escolar = $_GET['cod_rota'];

        $tmp_obj = new clsModulesRotaTransporteEscolar($cod_rota_transporte_escolar);
        $registro = $tmp_obj->detalhe();

        if (! $registro) {
            $this->simpleRedirect('transporte_rota_lst.php');
        }

        $this->addDetalhe(['Ano', $registro['ano']]);
        $this->addDetalhe(['Código da rota', $cod_rota_transporte_escolar]);
        $this->addDetalhe(['Descrição', $registro['descricao']]);
        $this->addDetalhe(['Destino', $registro['nome_destino']]);
        $this->addDetalhe(['Empresa', $registro['nome_empresa']]);
        $this->addDetalhe(['Tipo da rota', ($registro['tipo_rota'] == 'U' ? 'Urbana' : 'Rural')]);
        $this->addDetalhe(['Abrangência', clsModulesRotaTransporteEscolar::coverageName($registro['abrangencia'])]);
        $this->addDetalhe(['Dias de funcionamento', clsModulesRotaTransporteEscolar::weekdaysNames($registro['dias_semana'])]);
        if (trim($registro['km_pav'])!='') {
            $this->addDetalhe(['Percurso pavimentado', RoutePathMap::kilometersText($registro['km_pav'])]);
        }
        if (trim($registro['km_npav'])!='') {
            $this->addDetalhe(['Percurso não pavimentado', RoutePathMap::kilometersText($registro['km_npav'])]);
        }

        $this->addDetalhe(['Terceirizado', ($registro['tercerizado'] == 'S' ? 'Sim' : 'Não')]);

        // Itinerário

        $obj = new clsModulesItinerarioTransporteEscolar();
        $obj->setOrderby('seq ASC');
        $lst = $obj->lista(null, $cod_rota_transporte_escolar);
        $tabela = '';

        if ($lst) {
            $itineraryRows = [];

            foreach ($lst as $valor) {
                $obj_veiculo = new clsModulesVeiculo($valor['ref_cod_veiculo']);
                $obj_veiculo = $obj_veiculo->detalhe();

                $valor_veiculo = $obj_veiculo['descricao'] == '' ? '' : $this->vehicleText($obj_veiculo);

                $obj_ponto = new clsModulesPontoTransporteEscolar($valor['ref_cod_ponto_transporte_escolar']);
                $obj_ponto = $obj_ponto->detalhe();
                $valor_ponto = $obj_ponto['descricao'];

                $itineraryRows[] = [
                    e($valor['seq']),
                    e($valor_ponto),
                    e($valor['hora']),
                    $valor['tipo'] == 'V' ? 'Volta' : 'Ida',
                    e($valor_veiculo),
                ];
            }

            $tabela = '<div><a style=\'text-decoration: underline;\' href=\'/intranet/transporte_itinerario_cad.php?cod_rota='.$cod_rota_transporte_escolar.'\'>Editar itinerário</a></div>'
                . DetailTable::html(['Sequencial', 'Ponto', 'Hora', 'Tipo', 'Veículo'], $itineraryRows);
        }
        if ($tabela) {
            $this->addDetalhe(['Itinerário', $tabela]);
        } else {
            $this->addDetalhe(['Itinerário', '<a style=\' text-decoration: underline; font-size: 12px;\' href=\'/intranet/transporte_itinerario_cad.php?cod_rota='.$cod_rota_transporte_escolar.'\'>Editar itinerário</a>']);
        }

        $this->routePathMap = new RoutePathMap((int) $cod_rota_transporte_escolar, false);
        $this->addDetalhe($this->routePathMap->detailRow());

        $directionsDistances = $this->routePathMap->directionsDistances();

        if ($directionsDistances !== null) {
            $this->addDetalhe(['Km ida / Km volta', $directionsDistances]);
        }

        $obj_permissao = new clsPermissoes();

        if ($obj_permissao->permissao_cadastra(21238, $this->pessoa_logada, 7, null, true)) {
            $this->url_novo = '../module/TransporteEscolar/Rota';
        }
        if ($obj_permissao->permissao_editar(21238, $this->pessoa_logada, 7, null, true)) {
            $this->url_editar = "../module/TransporteEscolar/Rota?id={$cod_rota_transporte_escolar}";
        }

        $this->url_cancelar = 'transporte_rota_lst.php';

        $this->largura = '100%';

        $this->breadcrumb('Detalhe da rota', [
        url('intranet/educar_transporte_escolar_index.php') => 'Transporte escolar',
    ]);
    }

    public function makeExtra()
    {
        return $this->routePathMap instanceof RoutePathMap ? $this->routePathMap->script() : '';
    }

    public function makeCss()
    {
        return $this->routePathMap instanceof RoutePathMap ? $this->routePathMap->css() : '';
    }

    public function Formular()
    {
        $this->title = 'Rotas';
        $this->processoAp = 21238;
    }
};
