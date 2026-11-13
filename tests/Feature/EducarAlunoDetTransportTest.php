<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use Database\Factories\LegacyStudentFactory;
use iEducar\Packages\Transport\Tests\CreatesTransportUserRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Testing\TestResponse;
use Tests\LoginFirstUser;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportUserRecords.php';

class EducarAlunoDetTransportTest extends TestCase
{
    use CreatesTransportUserRecords, DatabaseTransactions, LoginFirstUser, WithoutMiddleware;

    private int $currentYear;

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $this->currentYear = (int) date('Y');
    }

    public function test_shows_the_current_year_link_and_the_history_from_newest_to_oldest(): void
    {
        $student = LegacyStudentFactory::new()->create();
        $person = $student->ref_idpes;
        $this->transportUserRecord($person, $this->routeRecord('Rota da Gameleira ENS011', $this->currentYear), [
            'ref_cod_ponto_transporte_escolar' => $this->stopRecord('Escola Municipal X ENS011'),
            'turno' => '1',
        ]);
        $this->transportUserRecord($person, $this->routeRecord('Pinto / Açude ENS011', $this->currentYear - 1), [
            'ref_cod_ponto_transporte_escolar' => $this->stopRecord('Açude ENS011'),
            'turno' => '2',
        ]);
        $this->transportUserRecord($person, $this->routeRecord('Rota antiga ENS011', $this->currentYear - 2));

        $this->studentDetail($student->getKey())
            ->assertSeeInOrder([
                'Utiliza transporte rural',
                "Vínculo de transporte {$this->currentYear}",
                'Rota da Gameleira ENS011',
                'Ponto: Escola Municipal X ENS011',
                'Turno: Matutino',
                'Histórico de transporte',
                '<td bgcolor="#ccdce6" role="columnheader" style="padding: 6px 14px; text-align: left; white-space: nowrap;"><b>Ano</b></td>',
                '<td bgcolor="#ccdce6" role="columnheader" style="padding: 6px 14px; text-align: left; white-space: nowrap;"><b>Rota</b></td>',
                '<td bgcolor="#ccdce6" role="columnheader" style="padding: 6px 14px; text-align: left; white-space: nowrap;"><b>Ponto de embarque</b></td>',
                '<td bgcolor="#ccdce6" role="columnheader" style="padding: 6px 14px; text-align: left; white-space: nowrap;"><b>Turno</b></td>',
                (string) ($this->currentYear - 1),
                'Pinto / Açude ENS011',
                'Açude ENS011',
                'Vespertino',
                (string) ($this->currentYear - 2),
                'Rota antiga ENS011',
            ], false)
            ->assertSee('bgcolor="#f5f9fd"', false)
            ->assertSee('overflow-x: auto;', false);
    }

    public function test_shows_only_the_current_year_link_without_history(): void
    {
        $student = LegacyStudentFactory::new()->create();
        $this->transportUserRecord($student->ref_idpes, $this->routeRecord('Rota da Gameleira ENS011', $this->currentYear));

        $this->studentDetail($student->getKey())
            ->assertSeeInOrder(["Vínculo de transporte {$this->currentYear}", 'Rota da Gameleira ENS011'], false)
            ->assertDontSee('Ponto:', false)
            ->assertDontSee('Turno:', false)
            ->assertDontSee('Histórico de transporte', false)
            ->assertDontSee('Sem vínculo de transporte', false);
    }

    public function test_shows_empty_state_without_any_link(): void
    {
        $student = LegacyStudentFactory::new()->create();

        $this->studentDetail($student->getKey())
            ->assertSeeInOrder(["Vínculo de transporte {$this->currentYear}", 'Sem vínculo de transporte'], false)
            ->assertDontSee('Histórico de transporte', false);
    }

    public function test_shows_empty_state_and_history_when_only_other_years_have_links(): void
    {
        $student = LegacyStudentFactory::new()->create();
        $this->transportUserRecord($student->ref_idpes, $this->routeRecord('Pinto / Açude ENS011', $this->currentYear - 1));

        $this->studentDetail($student->getKey())
            ->assertSeeInOrder([
                "Vínculo de transporte {$this->currentYear}",
                'Sem vínculo de transporte',
                'Histórico de transporte',
                'Pinto / Açude ENS011',
            ], false);
    }

    public function test_escapes_route_and_stop_names(): void
    {
        $student = LegacyStudentFactory::new()->create();
        $this->transportUserRecord($student->ref_idpes, $this->routeRecord('<b>Rota</b> ENS011', $this->currentYear), [
            'ref_cod_ponto_transporte_escolar' => $this->stopRecord("Ponto d'água <i>ENS011</i>"),
        ]);

        $this->studentDetail($student->getKey())
            ->assertSee('&lt;b&gt;Rota&lt;/b&gt; ENS011', false)
            ->assertSee('Ponto: Ponto d&#039;água &lt;i&gt;ENS011&lt;/i&gt;', false);
    }

    private function studentDetail(int $studentId): TestResponse
    {
        $_GET = ['cod_aluno' => $studentId];

        return $this->get('/intranet/educar_aluno_det.php?cod_aluno=' . $studentId)->assertSuccessful();
    }
}
