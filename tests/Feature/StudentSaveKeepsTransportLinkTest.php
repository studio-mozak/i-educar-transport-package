<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use Database\Factories\LegacyIndividualFactory;
use Database\Factories\LegacyMaritalStatusFactory;
use Database\Factories\LegacyStudentFactory;
use iEducar\Packages\Transport\Tests\CreatesTransportUserRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Api\DiarioApiRequestTestTrait;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportUserRecords.php';

class StudentSaveKeepsTransportLinkTest extends TestCase
{
    use CreatesTransportUserRecords, DatabaseTransactions, DiarioApiRequestTestTrait;

    public const ROUTE = '/module/Api/Aluno';

    private int $person;

    private int $guardian;

    private int $route;

    private int $stop;

    private int $destination;

    private int $link;

    protected function setUp(): void
    {
        parent::setUp();

        $maritalStatus = LegacyMaritalStatusFactory::new()->create(['ideciv' => 1, 'descricao' => 'Solteiro']);
        $this->person = LegacyIndividualFactory::new()->create(['ideciv' => $maritalStatus])->getKey();
        $this->guardian = LegacyIndividualFactory::new()->create(['ideciv' => $maritalStatus])->getKey();
        $this->stop = $this->stopRecord('Ponto ENS011');
        $this->destination = $this->destinationRecord();
        $this->route = $this->routeRecord('Rota da Gameleira ENS011', 2026);
        $this->link = $this->transportUserRecord($this->person, $this->route, [
            'ref_cod_ponto_transporte_escolar' => $this->stop,
            'ref_idpes_destino' => $this->destination,
            'observacao' => 'Embarca no portão',
            'turno' => '1',
        ]);
    }

    public function test_student_post_does_not_create_or_change_the_transport_link(): void
    {
        $studentId = $this->getResource(self::ROUTE, $this->studentForm(['oper' => 'post']))
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false)
            ->json('id');

        $this->assertIsNumeric($studentId);
        $this->assertLinkIsUntouched();
    }

    public function test_student_put_keeps_the_stop_and_destination_of_the_transport_link(): void
    {
        $student = LegacyStudentFactory::new()->create(['ref_idpes' => $this->person, 'tipo_transporte' => 0]);

        $this->getResource(self::ROUTE, $this->studentForm(['oper' => 'put', 'id' => $student->getKey()]))
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $this->assertSame(2, (int) DB::table('pmieducar.aluno')->where('cod_aluno', $student->getKey())->value('tipo_transporte'));
        $this->assertLinkIsUntouched();
    }

    public function test_student_get_no_longer_merges_the_transport_link(): void
    {
        $student = LegacyStudentFactory::new()->create(['ref_idpes' => $this->person]);

        $response = $this->getResource(self::ROUTE, ['oper' => 'get', 'resource' => 'aluno', 'id' => $student->getKey()])
            ->assertSuccessful();

        $this->assertSame($this->person, (int) $response->json('pessoa_id'));
        $this->assertNull($response->json('ref_cod_rota_transporte_escolar'));
        $this->assertNull($response->json('cod_pessoa_transporte'));
    }

    private function studentForm(array $overrides): array
    {
        return array_merge([
            'resource' => 'aluno',
            'pessoa_id' => $this->person,
            'mae_id' => $this->guardian,
            'tipo_responsavel' => 'mae',
            'alfabetizado' => 'checked',
            'material' => 'A',
            'tipo_transporte' => 'municipal',
            'deficiencias' => [],
            'transtornos' => [],
            'transporte_rota' => $this->routeRecord('Outra rota ENS011', 2026),
            'transporte_ponto' => '',
            'transporte_observacao' => 'Observação enviada pelo aluno',
            'pessoaj_id' => '',
        ], $overrides);
    }

    private function assertLinkIsUntouched(): void
    {
        $this->assertSame(1, $this->transportUserCount($this->person));

        $saved = $this->transportUser($this->link);
        $this->assertSame($this->route, (int) $saved->ref_cod_rota_transporte_escolar);
        $this->assertSame($this->stop, (int) $saved->ref_cod_ponto_transporte_escolar);
        $this->assertSame($this->destination, (int) $saved->ref_idpes_destino);
        $this->assertSame('Embarca no portão', $saved->observacao);
        $this->assertSame('1', $saved->turno);
        $this->assertSame(2026, (int) $saved->ano);
    }
}
