<?php

use clsModulesEmpresaTransporteEscolar;
use clsModulesMotorista;
use clsModulesVeiculo;

return new class extends clsListagem

{
    private $totalEmpresas;
    private $totalMotoristas;
    private $totalVeiculos;

    public function __construct()
    {
        $this->totalEmpresas = $this->getTotalEmpresas();
        $this->totalMotoristas = $this->getTotalMotoristas();
        $this->totalVeiculos = $this->getTotalVeiculos();
    }

    private function getTotalEmpresas(): int
    {
        $empresa = new clsModulesEmpresaTransporteEscolar();

        return $empresa->count();
    }

    private function getTotalMotoristas(): int
    {
        $motorista = new clsModulesMotorista();

        return $motorista->count();
    }

    private function getTotalVeiculos(): int
    {
        $veiculo = new clsModulesVeiculo();

        return $veiculo->count();
    }

    public function RenderHTML()
    {
        $this->breadcrumb(
            currentPage: 'Dashboard',
            breadcrumbs: [
                '/intranet/educar_transporte_escolar_index.php' => 'Transporte Escolar'
            ]
        );

        return '            
            <h1 class="title_ensinus">Módulo <strong>Transporte Escolar</strong></h1>
            <div>
                <div class="row">
                    ' . $this->renderCard('TOTAL DE EMPRESAS', $this->totalEmpresas) . '
                    ' . $this->renderCard('TOTAL DE MOTORISTAS', $this->totalMotoristas) . '
                    ' . $this->renderCard('TOTAL DE VEÍCULOS', $this->totalVeiculos) . '

                </div>
            </div>';
    }

    private function renderCard(string $titulo, int $valor): string
    {
        return '
        <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
            <div class="height-150 card-ensinus border-radius-8">
                <div class="card-body p-3 h-100 d-flex justify-content-around align-items-center">
                    <div class="row w-100">
                        <div class="col-8">
                            <div class="numbers">
                                <p class="text-sm mb-0 text-uppercase font-weight-bold">' . $titulo . '</p>
                                <h5 class="font-weight-bolder">' . $valor . '</h5>
                            </div>
                        </div>
                        <div class="col-4 text-end d-flex justify-content-end align-items-center">
                            <div class="d-flex justify-content-center align-items-center icontainer-icon bg-blur-ensinus shadow-primary text-center rounded-circle">
                                <img src="/assets/img/users-couple-svgrepo-com.svg">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>';
    }

    public function Formular()
    {
        $this->title = 'Transporte Escolar';
        $this->processoAp = 69;
    }
};
