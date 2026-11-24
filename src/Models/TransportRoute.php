<?php

namespace iEducar\Packages\Transport\Models;

use Illuminate\Database\Eloquent\Model;

class TransportRoute extends Model
{
    protected $table = 'modules.rota_transporte_escolar';

    protected $primaryKey = 'cod_rota_transporte_escolar';

    public $timestamps = false;
}
