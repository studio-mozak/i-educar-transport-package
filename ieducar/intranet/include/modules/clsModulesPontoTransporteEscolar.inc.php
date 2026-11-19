<?php

use iEducar\Legacy\Model;
use Illuminate\Support\Facades\DB;

class clsModulesPontoTransporteEscolar extends Model
{
    public const LATITUDE_LIMIT = 90;

    public const LONGITUDE_LIMIT = 180;

    public const COORDINATE_DECIMALS = 6;

    public const LATITUDE_FORMAT_MESSAGE = 'Informe a latitude em graus decimais. Ex.: -5.091234';

    public const LONGITUDE_FORMAT_MESSAGE = 'Informe a longitude em graus decimais. Ex.: -42.837512';

    public const LATITUDE_RANGE_MESSAGE = 'A latitude deve estar entre -90 e 90.';

    public const LONGITUDE_RANGE_MESSAGE = 'A longitude deve estar entre -180 e 180.';

    public const COORDINATES_TOGETHER_MESSAGE = 'Informe a latitude e a longitude juntas, ou deixe as duas em branco.';

    public const CITY_REQUIRED_MESSAGE = 'Informe o município.';

    private const COORDINATE_PATTERN = '/^-?\d+([.,]\d+)?$/';

    private const COORDINATE_FIELDS = [
        'latitude' => [self::LATITUDE_LIMIT, self::LATITUDE_FORMAT_MESSAGE, self::LATITUDE_RANGE_MESSAGE],
        'longitude' => [self::LONGITUDE_LIMIT, self::LONGITUDE_FORMAT_MESSAGE, self::LONGITUDE_RANGE_MESSAGE],
    ];

    public $cod_ponto_transporte_escolar;
    public $descricao;
    public $cep;
    public $idbai;
    public $idlog;
    public $complemento;
    public $numero;
    public $latitude;
    public $longitude;

    public function __construct($cod_ponto_transporte_escolar = null, $descricao = null)
    {

        $this->_schema = 'modules.';
        $this->_tabela = "{$this->_schema}ponto_transporte_escolar";

        $this->_campos_lista = $this->_todos_campos = ' cod_ponto_transporte_escolar, descricao, cep, idlog, idbai, complemento, numero, latitude, longitude ';

        if (is_numeric($cod_ponto_transporte_escolar)) {
            $this->cod_ponto_transporte_escolar = $cod_ponto_transporte_escolar;
        }

        if (is_string($descricao)) {
            $this->descricao = $descricao;
        }
    }

    public static function coordinatesFromInput($latitude, $longitude): array
    {
        $input = ['latitude' => self::coordinateText($latitude), 'longitude' => self::coordinateText($longitude)];
        $result = ['latitude' => null, 'longitude' => null, 'errors' => []];

        if ($input['latitude'] === '' && $input['longitude'] === '') {
            return $result;
        }

        foreach (self::COORDINATE_FIELDS as $field => [$limit, $formatMessage, $rangeMessage]) {
            $value = $input[$field];

            if ($value === '') {
                $result['errors'][$field] = self::COORDINATES_TOGETHER_MESSAGE;
            } elseif ($value === null || !preg_match(self::COORDINATE_PATTERN, $value)) {
                $result['errors'][$field] = $formatMessage;
            } elseif (self::normalizedCoordinate($value, $limit) === null) {
                $result['errors'][$field] = $rangeMessage;
            } else {
                $result[$field] = self::normalizedCoordinate($value, $limit);
            }
        }

        if ($result['errors'] !== []) {
            $result['latitude'] = null;
            $result['longitude'] = null;
        }

        return $result;
    }

    public static function normalizedCoordinate($value, int $limit = self::LONGITUDE_LIMIT): ?string
    {
        $value = self::coordinateText($value);

        if ($value === null || !preg_match(self::COORDINATE_PATTERN, $value)) {
            return null;
        }

        $number = round((float) str_replace(',', '.', $value), self::COORDINATE_DECIMALS);

        if (abs($number) > $limit) {
            return null;
        }

        $text = rtrim(rtrim(number_format($number, self::COORDINATE_DECIMALS, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    public function updateCoordinates(?string $latitude, ?string $longitude): bool
    {
        if (!is_numeric($this->cod_ponto_transporte_escolar)) {
            return false;
        }

        DB::table($this->_tabela)
            ->where('cod_ponto_transporte_escolar', $this->cod_ponto_transporte_escolar)
            ->update(['latitude' => $latitude, 'longitude' => $longitude]);

        return true;
    }

    private static function coordinateText($value): ?string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? trim((string) $value) : null;
    }

    /**
     * Cria um novo registro.
     *
     * @return bool
     */
    public function cadastra()
    {
        if (is_string($this->descricao)) {
            $db = new clsBanco();

            $campos = '';
            $valores = '';
            $gruda = '';

            if (is_string($this->descricao)) {
                $descricao = $db->escapeString($this->descricao);
                $campos .= "{$gruda}descricao";
                $valores .= "{$gruda}'{$descricao}'";
                $gruda = ', ';
            }

            if (is_numeric($this->cep)) {
                $campos .= "{$gruda}cep";
                $valores .= "{$gruda} {$this->cep}";
                $gruda = ', ';
            }

            if (is_numeric($this->idlog)) {
                $campos .= "{$gruda}idlog";
                $valores .= "{$gruda} {$this->idlog}";
                $gruda = ', ';
            }

            if (is_numeric($this->idbai)) {
                $campos .= "{$gruda}idbai";
                $valores .= "{$gruda} {$this->idbai}";
                $gruda = ', ';
            }

            if (is_numeric($this->numero)) {
                $campos .= "{$gruda}numero";
                $valores .= "{$gruda}'{$this->numero}'";
                $gruda = ', ';
            }

            if (is_string($this->complemento)) {
                $complemento = $db->escapeString($this->complemento);
                $campos .= "{$gruda}complemento";
                $valores .= "{$gruda}'{$complemento}'";
                $gruda = ', ';
            }

            $db->Consulta("INSERT INTO {$this->_tabela} ( $campos ) VALUES( $valores )");

            $this->cod_ponto_transporte_escolar = $db->InsertId("{$this->_tabela}_seq");

            if ($this->cod_ponto_transporte_escolar) {
                $this->detalhe();
            }

            return $this->cod_ponto_transporte_escolar;
        }

        return false;
    }

    /**
     * Edita os dados de um registro.
     *
     * @return bool
     */
    public function edita()
    {
        if (is_string($this->cod_ponto_transporte_escolar)) {
            $db = new clsBanco();
            $set = '';
            $gruda = '';

            if (is_string($this->descricao)) {
                $descricao = $db->escapeString($this->descricao);
                $set .= "{$gruda}descricao = '{$descricao}'";
                $gruda = ', ';
            }

            if (is_numeric($this->cep)) {
                $set .= "{$gruda}cep = '{$this->cep}'";
                $gruda = ', ';
            }

            if (is_numeric($this->idlog)) {
                $set .= "{$gruda}idlog = '{$this->idlog}'";
                $gruda = ', ';
            }

            if (is_numeric($this->idbai)) {
                $set .= "{$gruda}idbai = '{$this->idbai}'";
                $gruda = ', ';
            }

            if (is_string($this->complemento)) {
                $complemento = $db->escapeString($this->complemento);
                $set .= "{$gruda}complemento = '{$complemento}'";
                $gruda = ', ';
            }

            if (is_numeric($this->numero)) {
                $set .= "{$gruda}numero = '{$this->numero}'";
                $gruda = ', ';
            }

            if ($set) {
                $this->detalhe();
                $db->Consulta("UPDATE {$this->_tabela} SET $set WHERE cod_ponto_transporte_escolar = '{$this->cod_ponto_transporte_escolar}'");

                return true;
            }
        }

        return false;
    }

    /**
     * Retorna uma lista de registros filtrados de acordo com os parâmetros.
     *
     * @return array|false
     */
    public function lista($cod_ponto_transporte_escolar = null, $descricao = null)
    {
        $db = new clsBanco();

        $sql = "SELECT {$this->_campos_lista},

              (SELECT l.nome FROM public.logradouro l WHERE l.idlog = ponto_transporte_escolar.idlog) as logradouro,

              (SELECT l.idtlog FROM public.logradouro l WHERE l.idlog = ponto_transporte_escolar.idlog) as idtlog,

              (SELECT b.nome FROM public.bairro b WHERE b.idbai = ponto_transporte_escolar.idbai) as bairro,

              (SELECT b.zona_localizacao FROM public.bairro b WHERE b.idbai = ponto_transporte_escolar.idbai) as zona_localizacao,

              (SELECT m.nome FROM public.municipio m, public.logradouro l WHERE m.idmun = l.idmun AND l.idlog = ponto_transporte_escolar.idlog) as municipio,

              (SELECT m.sigla_uf FROM public.municipio m, public.logradouro l WHERE m.idmun = l.idmun AND l.idlog = ponto_transporte_escolar.idlog) as sigla_uf,

              (SELECT l.idmun FROM public.logradouro l WHERE l.idlog = ponto_transporte_escolar.idlog) as idmun,

              (SELECT bairro.iddis FROM public.bairro
                WHERE idbai = ponto_transporte_escolar.idbai) as iddis,

              (SELECT distrito.nome FROM public.distrito
                INNER JOIN public.bairro ON (bairro.iddis = distrito.iddis)
                WHERE idbai = ponto_transporte_escolar.idbai) as distrito

            FROM {$this->_tabela}
    ";
        $filtros = '';

        $whereAnd = ' WHERE ';

        if (is_numeric($cod_ponto_transporte_escolar)) {
            $filtros .= "{$whereAnd} cod_ponto_transporte_escolar = '{$cod_ponto_transporte_escolar}'";
            $whereAnd = ' AND ';
        }

        if (is_string($descricao)) {
            $desc = $db->escapeString($descricao);
            $filtros .= "{$whereAnd} translate(upper(descricao),'ÅÁÀÃÂÄÉÈÊËÍÌÎÏÓÒÕÔÖÚÙÛÜÇÝÑ','AAAAAAEEEEIIIIOOOOOUUUUCYN') LIKE translate(upper('%{$desc}%'),'ÅÁÀÃÂÄÉÈÊËÍÌÎÏÓÒÕÔÖÚÙÛÜÇÝÑ','AAAAAAEEEEIIIIOOOOOUUUUCYN')";
            $whereAnd = ' AND ';
        }

        $countCampos = count(explode(',', $this->_campos_lista)) + 2;
        $resultado = [];

        $sql .= $filtros . $this->getOrderby() . $this->getLimite();

        $this->_total = $db->CampoUnico("SELECT COUNT(0) FROM {$this->_tabela} {$filtros}");

        $db->Consulta($sql);

        if ($countCampos > 1) {
            while ($db->ProximoRegistro()) {
                $tupla = $db->Tupla();
                $tupla['_total'] = $this->_total;
                $resultado[] = $tupla;
            }
        } else {
            while ($db->ProximoRegistro()) {
                $tupla = $db->Tupla();
                $resultado[] = $tupla[$this->_campos_lista];
            }
        }
        if (count($resultado)) {
            return $resultado;
        }

        return false;
    }

    /**
     * Retorna um array com os dados de um registro.
     *
     * @return array|false
     */
    public function detalhe()
    {
        if (is_numeric($this->cod_ponto_transporte_escolar)) {
            $db = new clsBanco();
            $db->Consulta("SELECT {$this->_campos_lista},

              (SELECT l.nome FROM public.logradouro l WHERE l.idlog = ponto_transporte_escolar.idlog) as logradouro,

              (SELECT l.idtlog FROM public.logradouro l WHERE l.idlog = ponto_transporte_escolar.idlog) as idtlog,

              (SELECT b.nome FROM public.bairro b WHERE b.idbai = ponto_transporte_escolar.idbai) as bairro,

              (SELECT b.zona_localizacao FROM public.bairro b WHERE b.idbai = ponto_transporte_escolar.idbai) as zona_localizacao,

              (SELECT m.nome FROM public.municipio m, public.logradouro l WHERE m.idmun = l.idmun AND l.idlog = ponto_transporte_escolar.idlog) as municipio,

              (SELECT m.sigla_uf FROM public.municipio m, public.logradouro l WHERE m.idmun = l.idmun AND l.idlog = ponto_transporte_escolar.idlog) as sigla_uf,

              (SELECT l.idmun FROM public.logradouro l WHERE l.idlog = ponto_transporte_escolar.idlog) as idmun,

              (SELECT bairro.iddis FROM public.bairro
                WHERE idbai = ponto_transporte_escolar.idbai) as iddis,

              (SELECT distrito.nome FROM public.distrito
                INNER JOIN public.bairro ON (bairro.iddis = distrito.iddis)
                WHERE idbai = ponto_transporte_escolar.idbai) as distrito

            FROM {$this->_tabela} WHERE cod_ponto_transporte_escolar = '{$this->cod_ponto_transporte_escolar}'");
            $db->ProximoRegistro();

            return $db->Tupla();
        }

        return false;
    }

    /**
     * Retorna um array com os dados de um registro.
     *
     * @return array|false
     */
    public function existe()
    {
        if (is_numeric($this->cod_ponto_transporte_escolar)) {
            $db = new clsBanco();
            $db->Consulta("SELECT 1 FROM {$this->_tabela} WHERE cod_ponto_transporte_escolar = '{$this->cod_ponto_transporte_escolar}'");
            $db->ProximoRegistro();

            return $db->Tupla();
        }

        return false;
    }

    /**
     * Exclui um registro.
     *
     * @return bool
     */
    public function excluir()
    {
        if (is_numeric($this->cod_ponto_transporte_escolar)) {
            $this->detalhe();

            $sql = "DELETE FROM {$this->_tabela} WHERE cod_ponto_transporte_escolar = '{$this->cod_ponto_transporte_escolar}'";
            $db = new clsBanco();
            $db->Consulta($sql);

            return true;
        }

        return false;
    }
}
