<?php

use App\Models\City;
use App\Models\Place;
use iEducar\Packages\Transport\Services\AddressLocationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class PontoController extends ApiCoreController
{
    public const LOCATE_FORBIDDEN_MESSAGE = 'Usuário sem permissão para cadastrar ou editar pontos.';

    protected $_processoAp = 21239;
    protected $_nivelAcessoOption = App_Model_NivelAcesso::SOMENTE_ESCOLA; // verificar

    private ?array $coordinates = null;

    private ?Place $place = null;

    private array $addressValues = [];

    private bool $hasAddress = false;

    protected function validatesCoordinates(): bool
    {
        $sentCoordinates = $this->sentFields([
            'latitude' => $this->getRequest()->latitude,
            'longitude' => $this->getRequest()->longitude,
        ]);

        $this->coordinates = null;

        if ($sentCoordinates === []) {
            return true;
        }

        if (count($sentCoordinates) === 1) {
            $this->messenger->append(clsModulesPontoTransporteEscolar::COORDINATES_TOGETHER_MESSAGE, 'error');

            return false;
        }

        $coordinates = clsModulesPontoTransporteEscolar::coordinatesFromInput(
            $sentCoordinates['latitude'],
            $sentCoordinates['longitude']
        );

        foreach ($coordinates['errors'] as $message) {
            $this->messenger->append($message, 'error');
        }

        if ($coordinates['errors'] !== []) {
            return false;
        }

        $this->coordinates = ['latitude' => $coordinates['latitude'], 'longitude' => $coordinates['longitude']];

        return true;
    }

    private function sentFields(array $values): array
    {
        return array_filter(
            $values,
            fn ($field) => array_key_exists($field, $_POST) || array_key_exists($field, $_GET),
            ARRAY_FILTER_USE_KEY
        );
    }

    protected function validatesAddress($id = null): bool
    {
        $this->place = $this->savedPlace($id);

        $requestAddress = $this->requestAddress();
        $this->addressValues = $this->place ? $this->sentFields($requestAddress) : $requestAddress;

        $address = $this->place
            ? array_merge($this->place->only(array_keys($requestAddress)), $this->addressValues)
            : $this->addressValues;

        $this->hasAddress = array_filter(
            Arr::except($address, ['city_id']),
            fn ($value) => filled($value)
        ) !== [];

        if ($this->hasAddress && blank($address['city_id'])) {
            $this->messenger->append(clsModulesPontoTransporteEscolar::CITY_REQUIRED_MESSAGE, 'error');

            return false;
        }

        return true;
    }

    private function savedPlace($id): ?Place
    {
        $stop = (new clsModulesPontoTransporteEscolar($id))->detalhe();

        if (!$stop || !is_numeric($stop['idlog'])) {
            return null;
        }

        return Place::query()->find($stop['idlog']);
    }

    private function requestAddress(): array
    {
        return [
            'address' => $this->getRequest()->address,
            'number' => $this->getRequest()->number ?: null,
            'complement' => $this->getRequest()->complement,
            'neighborhood' => $this->getRequest()->neighborhood,
            'city_id' => $this->getRequest()->city_id ?: null,
            'postal_code' => idFederal2int((string) $this->getRequest()->postal_code),
        ];
    }

    protected function createOrUpdatePonto($id = null)
    {
        $ponto = new clsModulesPontoTransporteEscolar;
        $ponto->cod_ponto_transporte_escolar = $id;
        $ponto->descricao = $this->getRequest()->desc;

        if ($this->place || $this->hasAddress) {
            $place = $this->place ?? new Place;
            $place->fill($this->addressValues);
            $place->save();

            $ponto->cep = $place->postal_code;
            $ponto->idbai = $place->getKey();
            $ponto->idlog = $place->getKey();
            $ponto->numero = $place->number;
            $ponto->complemento = $place->complement;
        }

        $saved = is_null($id) ? $ponto->cadastra() : $ponto->edita();

        if ($saved && $this->coordinates !== null) {
            $ponto->updateCoordinates($this->coordinates['latitude'], $this->coordinates['longitude']);
        }

        return $saved;
    }

    protected function get()
    {
        $id = $this->getRequest()->id;
        $ponto = new clsModulesPontoTransporteEscolar();
        $ponto->cod_ponto_transporte_escolar = $id;
        $ponto = $ponto->detalhe();

        $attrs = [
            'cod_ponto_transporte_escolar' => 'id',
            'descricao' => 'desc',
            'cep' => 'cep',
            'idlog' => 'idlog',
            'idbai' => 'idbai',
            'numero' => 'numero',
            'complemento' => 'complemento',
            'bairro' => 'bairro',
            'distrito' => 'distrito',
            'iddis' => 'iddis',
            'logradouro' => 'logradouro',
            'idtlog' => 'idtlog',
            'zona_localizacao' => 'zona_localizacao',
            'sigla_uf' => 'sigla_uf',
            'municipio' => 'municipio',
            'idmun' => 'idmun',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
        ];

        $pt = Portabilis_Array_Utils::filter($ponto, $attrs);
        $pt['desc'] = Portabilis_String_Utils::toUtf8($pt['desc']);

        $pt['bairro'] = $this->toUtf8($pt['bairro']);
        $pt['distrito'] = $this->toUtf8($pt['distrito']);
        $pt['logradouro'] = $this->toUtf8($pt['logradouro']);

        $pt['municipio'] = $this->toUtf8($pt['municipio']);
        $pt['sigla_uf'] = $this->toUtf8($pt['sigla_uf']);

        $pt['cep'] = int2CEP($pt['cep']);

        return $pt;
    }

    protected function validateIfPontoIsNotInUse()
    {
        $itinerario = new clsModulesItinerarioTransporteEscolar();
        $lista = $itinerario->lista(null, null, null, null, null, $this->getRequest()->id);

        if (is_array($lista) && count($lista)>0) {
            $this->messenger->append(
                'Não é possível excluir um ponto que está vinculado a um itinerário.',
                'error',
                false,
                'error'
            );

            return false;
        } else {
            return true;
        }
    }

    protected function post()
    {
        if (!($this->getRequest()->cep_ && is_numeric($this->getRequest()->bairro_id) && is_numeric($this->getRequest()->logradouro_id))) {
            $this->normalizaEndereco();
        }

        $id = $this->createOrUpdatePonto();
        if (is_numeric($id)) {
            $this->messenger->append('Cadastro realizado com sucesso', 'success', false, 'error');
        } else {
            $this->messenger->append('Aparentemente o ponto não pode ser cadastrada, por favor, verifique.');
        }

        return ['id' => $id];
    }

    protected function sqlsForNumericSearch()
    {
        $sqls[] = 'select distinct cod_ponto_transporte_escolar as id, descricao as name from
            modules.ponto_transporte_escolar where cod_ponto_transporte_escolar::varchar like $1||\'%\'';

        return $sqls;
    }

    protected function sqlsForStringSearch()
    {
        $sqls[] = 'select distinct cod_ponto_transporte_escolar as id, descricao as name  from
            modules.ponto_transporte_escolar where lower((descricao)) like \'%\'||lower(($1))||\'%\'';

        return $sqls;
    }

    protected function put()
    {
        $id = $this->getRequest()->id;

        if (!($this->getRequest()->cep_ && is_numeric($this->getRequest()->bairro_id) && is_numeric($this->getRequest()->logradouro_id))) {
            $this->normalizaEndereco();
        }

        $editou = $this->createOrUpdatePonto($id);

        if ($editou) {
            $this->messenger->append('Alteração realizada com sucesso', 'success', false, 'error');
        } else {
            $this->messenger->append('Aparentemente o ponto não pode ser alterado, por favor, verifique.');
        }

        return ['id' => $id];
    }

    protected function delete()
    {
        $id = $this->getRequest()->id;
        $pessoas = new clsModulesPessoaTransporte();
        $lista = $pessoas->lista(null, null, null, $id);

        foreach ($lista as $registro) {
            $editaPessoa = new clsModulesPessoaTransporte(
                $registro['cod_pessoa_transporte'],
                $registro['ref_cod_rota_transporte_escolar'],
                $registro['ref_idpes'],
                null,
                $registro['ref_idpes_destino'],
                $registro['observacao']
            );
            $editaPessoa->ref_cod_ponto_transporte_escolar = '';

            $editaPessoa->edita();
        }

        $ponto = new clsModulesPontoTransporteEscolar();
        $ponto->cod_ponto_transporte_escolar = $id;

        if ($ponto->excluir()) {
            $this->messenger->append('Cadastro removido com sucesso', 'success', false, 'error');
        } else {
            $this->messenger->append(
                'Aparentemente o cadastro não pode ser removido, por favor, verifique.',
                'error',
                false,
                'error'
            );
        }

        return ['id' => $id];
    }

    protected function normalizaEndereco()
    {
        // TODO Addressing
    }

    private function validatesAuthorizationToLocate(): bool
    {
        $permissions = $this->getClsPermissoes();
        $process = $this->getBaseProcessoAp();
        $user = Auth::id();

        $can = $permissions->permissao_cadastra($process, $user, $this->_nivelAcessoOption)
            || $permissions->permissao_editar($process, $user, $this->_nivelAcessoOption);

        if (!$can) {
            $this->messenger->append(self::LOCATE_FORBIDDEN_MESSAGE);
        }

        return $can;
    }

    private function getAddressLocation(): array
    {
        if (!$this->validatesAuthorizationToLocate()) {
            return [];
        }

        $cityId = $this->getRequest()->city_id;
        $city = is_numeric($cityId) ? City::query()->with('state')->find((int) $cityId) : null;

        return (new AddressLocationService)->locate([
            'address' => $this->getRequest()->address,
            'number' => $this->getRequest()->number,
            'neighborhood' => $this->getRequest()->neighborhood,
            'city' => $city?->name,
            'state' => $city?->state?->abbreviation,
        ]);
    }

    public function Gerar()
    {
        if ($this->isRequestFor('get', 'ponto')) {
            $this->appendResponse($this->get());
        } elseif ($this->isRequestFor('get', 'ponto-search')) {
            $this->appendResponse($this->search());
        } elseif ($this->isRequestFor('get', AddressLocationService::API_RESOURCE)) {
            $this->appendResponse($this->getAddressLocation());
        }

        // create
        elseif ($this->isRequestFor('post', 'ponto')) {
            if ($this->validatesAuthorizationToChange() && $this->validatesCoordinates() && $this->validatesAddress()) {
                $this->appendResponse($this->post());
            }
        }

        // update
        elseif ($this->isRequestFor('put', 'ponto')) {
            if ($this->validatesAuthorizationToEdit() && $this->validatesCoordinates() && $this->validatesAddress($this->getRequest()->id)) {
                $this->appendResponse($this->put());
            }
        } elseif ($this->isRequestFor('delete', 'ponto')) {
            if ($this->validatesAuthorizationToDestroy() && $this->validateIfPontoIsNotInUse()) {
                $this->appendResponse($this->delete());
                echo '<script language= "JavaScript">
                    location.href="intranet/transporte_ponto_lst.php";
                    </script>';

                die();
            }
        } else {
            $this->notImplementedOperationError();
        }
    }
}
