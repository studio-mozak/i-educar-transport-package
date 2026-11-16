<?php

use App\Models\Enums\DayOfWeek;
use iEducar\Packages\Transport\Services\RoutePathService;
use iEducar\Packages\Transport\View\RoutePathMap;

return new class extends clsCadastro {
    /**
     * Referencia pega da session para o idpes do usuario atual
     *
     * @var int
     */
    public $pessoa_logada;

    public $cod_rota;
    public $descricao;
    public $abrangencia;
    public $dias_semana;
    public $weekdays_group;
    public $rejectedInput = false;
    public $km_ida;
    public $km_volta;
    public $km_pav;
    public $km_npav;
    public $trajeto_ida;
    public $trajeto_volta;
    public $trajeto_hash;

    private $routePathMap;

    private const INVALID_DISTANCE_MESSAGE = 'Quilometragem ou trajeto inválidos.<br>';

    private const STOPS_KEPT_MESSAGE = 'Abrangência e dias salvos. Os pontos do itinerário não foram removidos; para apagar todos, use Excluir todos.<br>';

    // INCLUI NOVO
    public $pontos;
    public $ref_cod_ponto_transporte_escolar;
    public $hora;
    public $tipo;
    public $ref_cod_veiculo;

    //------INCLUI DISCIPLINA------//
    public $historico_disciplinas;
    public $nm_disciplina;
    public $nota;
    public $faltas;
    public $excluir_disciplina;
    public $ultimo_sequencial;

    public $aceleracao;

    public function Inicializar()
    {
        $retorno = 'Editar';

        $this->cod_rota=$_GET['cod_rota'];

        $obj_permissoes = new clsPermissoes();
        $obj_permissoes->permissao_cadastra(21238, $this->pessoa_logada, 7, "transporte_rota_det.php?cod_rota={$this->cod_rota}");

        if (!$this->loadSavedRoute()) {
            $this->simpleRedirect('transporte_rota_lst.php');
        }

        return $retorno;
    }

    private function loadSavedRoute(): bool
    {
        if (!is_numeric($this->cod_rota)) {
            return false;
        }

        $registro = (new clsModulesRotaTransporteEscolar($this->cod_rota))->detalhe();

        if (!$registro) {
            return false;
        }

        $this->descricao = $registro['descricao'];
        $this->abrangencia = $registro['abrangencia'];
        $this->dias_semana = clsModulesRotaTransporteEscolar::weekdaysFromColumn($registro['dias_semana']);

        foreach (clsModulesRotaTransporteEscolar::DISTANCE_COLUMNS as $column) {
            $this->$column = $this->routePathMap()->savedDistance($column);
        }

        $this->url_cancelar = "transporte_rota_det.php?cod_rota={$this->cod_rota}";
        $this->nome_url_cancelar = 'Cancelar';

        $this->breadcrumb('Editar itinerário', [
            url('intranet/educar_transporte_escolar_index.php') => 'Transporte escolar',
        ]);

        return true;
    }

    private function routePathMap(): RoutePathMap
    {
        if (!$this->routePathMap instanceof RoutePathMap) {
            $this->routePathMap = new RoutePathMap((int) $this->cod_rota, true);
        }

        return $this->routePathMap;
    }

    private function routePathInput(): ?array
    {
        $values = [];

        foreach (clsModulesRotaTransporteEscolar::DISTANCE_COLUMNS as $column) {
            if (!array_key_exists($column, $_POST)) {
                continue;
            }

            $distance = RoutePathService::distanceFromInput($this->$column);

            if ($distance === false) {
                return null;
            }

            $values[$column] = $distance;
        }

        if (!filled($this->trajeto_hash)) {
            return $values;
        }

        $path = RoutePathService::pathFromInput($this->trajeto_ida, $this->trajeto_volta, $this->trajeto_hash);

        return $path === null ? null : $values + $path;
    }

    private function savedStops(): array
    {
        $itinerary = new clsModulesItinerarioTransporteEscolar();
        $itinerary->setOrderby(' seq ASC');

        return $itinerary->lista(null, $this->cod_rota) ?: [];
    }

    public function Gerar()
    {
        $showSavedRoute = !$_POST || $this->rejectedInput;

        if (!$showSavedRoute) {
            foreach ($_POST as $campo => $val) {
                $this->$campo = (!$this->$campo) ?  $val : $this->$campo ;
            }
        }

        $this->campoRotulo('cod_rota', 'Código da rota', $this->cod_rota);
        $this->campoRotulo('descricao', 'Rota', $this->descricao);
        $this->campoLista('abrangencia', 'Abrangência', ['' => 'Selecione'] + clsModulesRotaTransporteEscolar::COVERAGES, $this->abrangencia, '', false, '', '', false, false);
        $this->campoRotulo('weekdays_group', 'Dias de funcionamento', $this->weekdaysCheckboxes());

        //---------------------INCLUI DISCIPLINAS---------------------//
        $this->campoQuebra();

        if (is_numeric($this->cod_rota) && $showSavedRoute) {
            $registros = $this->savedStops();
            $qtd_pontos = 0;
            if ($registros) {
                foreach ($registros as $campo) {
                    //$this->pontos[$qtd_pontos][] = $campo["cod_itinerario_transporte_escolar"];
                    $this->pontos[$qtd_pontos][] = $campo['ref_cod_ponto_transporte_escolar'].' - '.$campo['descricao'];
                    //$this->pontos[$qtd_pontos][] = $campo["descricao"];
                    $this->pontos[$qtd_pontos][] = $campo['hora'];
                    $this->pontos[$qtd_pontos][] = $campo['tipo'];
                    $this->pontos[$qtd_pontos][] = $campo['ref_cod_veiculo'].' - '.$campo['nome_onibus'];
                    //$this->pontos[$qtd_pontos][] = $campo["seq"];
                    $qtd_pontos++;
                }
            }
        }

        $this->campoTabelaInicio('pontos', 'Itinerário', ['Ponto (Requer pré-cadastro)<br/> <spam style=" font-weight: normal; font-size: 10px;">Digite o código ou nome do ponto e selecione o desejado</spam>','Hora','Tipo','Veículo (Requer pré-cadastro)<br/> <spam style=" font-weight: normal; font-size: 10px;">Digite o código, nome ou placa do veículo e selecione o desejado</spam>' ], $this->pontos);

        $this->campoTexto('ref_cod_ponto_transporte_escolar', 'Ponto (Requer pré-cadastro)', $this->ref_cod_ponto_transporte_escolar, 50, 255, false, true, false, '', '', '', 'onfocus');

        $this->campoHora('hora', 'Hora', $this->hora);
        $this->campoLista('tipo', 'Tipo', [ '' => 'Selecione', 'I' => 'Ida', 'V' => 'Volta'], $this->tipo);
        $this->campoTexto('ref_cod_veiculo', 'Veículo', $this->ref_cod_veiculo, 50, 255, false, false, false, '', '', '', 'onfocus');
        $this->campoTabelaFim();

        $this->campoQuebra();
        //---------------------FIM INCLUI DISCIPLINAS---------------------//

        $this->addHtml($this->routePathMap()->formRow());
        $this->campoMonetario('km_ida', 'Km ida', $this->km_ida, 10, 10);
        $this->campoMonetario('km_volta', 'Km volta', $this->km_volta, 10, 10);
        $this->campoMonetario('km_pav', 'Percurso pavimentado', $this->km_pav, 10, 10, false, '', '', 'onChange', false, true, 'km');
        $this->campoMonetario('km_npav', 'Percurso não pavimentado', $this->km_npav, 10, 10, false, '', '', 'onChange', false, true, 'km');
        $this->campoOculto('trajeto_ida', '');
        $this->campoOculto('trajeto_volta', '');
        $this->campoOculto('trajeto_hash', '');

        // carrega estilo para feedback messages, para exibir msg validação frequencia.

        $style = '/vendor/legacy/Portabilis/Assets/Stylesheets/Frontend.css';
        Portabilis_View_Helper_Application::loadStylesheet($this, $style);

        Portabilis_View_Helper_Application::loadJavascript(
            $this, [
                '/vendor/legacy/Portabilis/Assets/Javascripts/Frontend/Inputs/SimpleSearch.js',
                '/vendor/legacy/Portabilis/Assets/Javascripts/Validator.js'
            ]
        );
        $this->addBotao('Excluir todos', "transporte_itinerario_del.php?cod_rota={$this->cod_rota}");
    }

    public function Novo()
    {
        return true;
    }

    public function Editar()
    {
        $obj_permissoes = new clsPermissoes();
        $obj_permissoes->permissao_editar(21238, $this->pessoa_logada, 7, "transporte_rota_det.php?cod_rota={$this->cod_rota}");

        $route = new clsModulesRotaTransporteEscolar($this->cod_rota);
        $routePath = $this->routePathInput();

        if ($routePath === null) {
            $this->mensagem = self::INVALID_DISTANCE_MESSAGE;
            $this->showSavedRouteAfterRejection();

            return false;
        }

        if (!$route->updateCoverageAndWeekdays($this->abrangencia, $this->dias_semana)) {
            $this->mensagem = 'Abrangência ou dias de funcionamento inválidos.<br>';
            $this->showSavedRouteAfterRejection();

            return false;
        }

        $route->updateDistancesAndPath($routePath);

        if (!$this->ref_cod_ponto_transporte_escolar && $this->savedStops() !== []) {
            $this->mensagem = self::STOPS_KEPT_MESSAGE;
            $this->simpleRedirect("transporte_rota_det.php?cod_rota={$this->cod_rota}");
        }

        if ($this->ref_cod_ponto_transporte_escolar) {
            $obj  = new clsModulesItinerarioTransporteEscolar();

            $excluiu = $obj->excluirTodos($this->cod_rota);

            if ($excluiu) {
                $sequencial = 1;
                foreach ($this->ref_cod_ponto_transporte_escolar as $key => $ponto) {
                    $obj = new clsModulesItinerarioTransporteEscolar(
                        null,
                        $this->cod_rota,
                        $sequencial,
                        $this->retornaCodigo($ponto),
                        $this->retornaCodigo($this->ref_cod_veiculo[$key]),
                        $this->hora[$key],
                        $this->tipo[$key]
                    );
                    $cadastrou1 = $obj->cadastra();
                    if (!$cadastrou1) {
                        $this->mensagem = 'Cadastro não realizado.<br>';

                        return false;
                    }
                    $sequencial++;
                }
            }
        }

        $this->mensagem .= 'Edição efetuada com sucesso.<br>';
        $this->simpleRedirect("transporte_rota_det.php?cod_rota={$this->cod_rota}");
    }

    private function showSavedRouteAfterRejection(): void
    {
        $this->loadSavedRoute();
        $this->rejectedInput = true;
        $this->pontos = null;
        $this->ref_cod_ponto_transporte_escolar = null;
        $this->hora = null;
        $this->tipo = null;
        $this->ref_cod_veiculo = null;
        $this->trajeto_ida = null;
        $this->trajeto_volta = null;
        $this->trajeto_hash = null;
    }

    private function weekdaysCheckboxes(): string
    {
        $checkedDays = clsModulesRotaTransporteEscolar::weekdaysFromInput($this->dias_semana) ?? [];
        $checkboxes = array_map(function (DayOfWeek $day) use ($checkedDays) {
            $checked = in_array($day->value, $checkedDays, true) ? ' checked' : '';

            return "<label for=\"dias_semana_{$day->value}\" style=\"margin: 0 12px 0 0; font-weight: normal; cursor: pointer;\"><input type=\"checkbox\" name=\"dias_semana[]\" id=\"dias_semana_{$day->value}\" value=\"{$day->value}\"{$checked}> {$day->shortName()}</label>";
        }, clsModulesRotaTransporteEscolar::WEEKDAYS_IN_SCREEN_ORDER);

        [$mondayToThursday, $fridayToSunday] = array_chunk($checkboxes, 4);

        $wrappingRow = 'display: flex; flex-wrap: wrap; row-gap: 6px;';

        return "<span role=\"group\" aria-label=\"Dias de funcionamento\" style=\"{$wrappingRow} max-width: calc(100vw - 280px);\">"
            . "<span style=\"{$wrappingRow}\">" . implode('', $mondayToThursday) . '</span>'
            . "<span style=\"{$wrappingRow}\">" . implode('', $fridayToSunday) . '</span>'
            . '</span>';
    }

    public function Excluir()
    {
        return true;
    }

    protected function retornaCodigo($palavra)
    {
        return substr($palavra, 0, strpos($palavra, ' -'));
    }

    protected function fixupFrequencia($frequencia)
    {
        if (strpos($frequencia, ',')) {
            $frequencia = str_replace('.', '', $frequencia);
            $frequencia = str_replace(',', '.', $frequencia);
        }

        return $frequencia;
    }

    public function makeExtra()
    {
        return file_get_contents(__DIR__ . '/scripts/transporte-itinerario-cad.js') . "\n" . $this->routePathMap()->script();
    }

    public function makeCss()
    {
        return $this->routePathMap()->css();
    }

    public function Formular()
    {
        $this->title = 'Itinerário';
        $this->processoAp = '21238';
    }
};
