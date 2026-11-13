<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesTransportUserRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Tests\LoginFirstUser;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportUserRecords.php';

class TransportUserYearViewTest extends TestCase
{
    use CreatesTransportUserRecords, DatabaseTransactions, LoginFirstUser, WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenControllerIsTaken('PessoatransporteController', \Portabilis_Controller_Page_EditController::class);
        $_GET = [];
    }

    public function test_form_shows_read_only_school_year_after_the_route(): void
    {
        $this->routeRecord('Rota da Gameleira ENS011', 2026);

        $this->get('/module/TransporteEscolar/Pessoatransporte')
            ->assertSuccessful()
            ->assertSee('Rota da Gameleira ENS011 - 2026', false)
            ->assertSeeInOrder(["id='rota'", "for='ano'>Ano letivo</label>", "id='ponto'"], false)
            ->assertSee('name="ano" id="ano" value="-" size="5" maxlength="4"  disabled', false);
    }
}
