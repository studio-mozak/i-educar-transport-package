<?php

namespace iEducar\Packages\Transport\Models;

use Illuminate\Database\Eloquent\Model;

class TransportUser extends Model
{
    protected $table = 'modules.pessoa_transporte';

    protected $primaryKey = 'cod_pessoa_transporte';

    public $timestamps = false;
}
