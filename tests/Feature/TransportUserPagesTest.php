<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesTransportUserRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\DB;
use Tests\LoginFirstUser;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportUserRecords.php';

class TransportUserPagesTest extends TestCase
{
    use CreatesTransportUserRecords, DatabaseTransactions, LoginFirstUser, WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
    }

    public function test_list_shows_the_year_column_after_the_person_name(): void
    {
        $person = $this->personRecord();
        $personName = DB::table('cadastro.pessoa')->where('idpes', $person)->value('nome');
        $this->transportUserRecord($person, $this->routeRecord('Rota da Gameleira ENS011', 2026));

        $_GET = ['nome_pessoa' => $personName];

        $this->get('/intranet/transporte_pessoa_lst.php?' . http_build_query($_GET))
            ->assertSuccessful()
            ->assertSeeInOrder(['Código', 'Nome da pessoa', 'Ano', 'Rota', 'Destino', 'Ponto de embarque'], false)
            ->assertSeeInOrder([$personName, '2026', 'Rota da Gameleira ENS011'], false);
    }

    public function test_list_filters_by_year(): void
    {
        $person = $this->personRecord();
        $personName = DB::table('cadastro.pessoa')->where('idpes', $person)->value('nome');
        $this->transportUserRecord($person, $this->routeRecord('Rota de 2026 ENS011', 2026));
        $this->transportUserRecord($person, $this->routeRecord('Rota de 2027 ENS011', 2027));

        $_GET = ['nome_pessoa' => $personName, 'ano_rota' => '2027'];

        $this->get('/intranet/transporte_pessoa_lst.php?' . http_build_query($_GET))
            ->assertSuccessful()
            ->assertSee('Rota de 2027 ENS011', false)
            ->assertDontSee('Rota de 2026 ENS011', false);
    }

    public function test_detail_shows_the_year_before_the_route(): void
    {
        $link = $this->transportUserRecord($this->personRecord(), $this->routeRecord('Rota da Gameleira ENS011', 2026));

        $_GET = ['cod_pt' => $link];

        $this->get('/intranet/transporte_pessoa_det.php?cod_pt=' . $link)
            ->assertSuccessful()
            ->assertSeeInOrder(['Pessoa', 'Ano letivo', '2026', 'Rota', 'Rota da Gameleira ENS011'], false);
    }
}
