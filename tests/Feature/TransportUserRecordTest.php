<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Tests\CreatesTransportUserRecords;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportUserRecords.php';

class TransportUserRecordTest extends TestCase
{
    use CreatesTransportUserRecords, DatabaseTransactions;

    public function test_database_rejects_two_links_of_the_same_person_in_the_same_year(): void
    {
        $person = $this->personRecord();
        $this->transportUserRecord($person, $this->routeRecord('Rota A ENS011', 2026));

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn () => $this->transportUserRecord($person, $this->routeRecord('Rota B ENS011', 2026)));
    }

    public function test_list_filters_by_year_destination_name_and_destination_id(): void
    {
        $destination = $this->destinationRecord();
        $person = $this->personRecord();
        $link2026 = $this->transportUserRecord($person, $this->routeRecord('Rota A ENS011', 2026), [
            'ref_idpes_destino' => $destination,
        ]);
        $link2027 = $this->transportUserRecord($person, $this->routeRecord('Rota A ENS011', 2027));
        $destinationName = DB::table('cadastro.pessoa')->where('idpes', $destination)->value('nome');

        $byYear = (new \clsModulesPessoaTransporte)->lista(null, $person, null, null, null, null, null, 2027);
        $byDestinationName = (new \clsModulesPessoaTransporte)->lista(null, $person, null, null, null, null, $destinationName);
        $byDestinationId = (new \clsModulesPessoaTransporte)->lista(null, null, null, null, $destination);

        $this->assertSame([$link2027], array_map('intval', array_column($byYear, 'cod_pessoa_transporte')));
        $this->assertSame(2027, (int) $byYear[0]['ano']);
        $this->assertSame([$link2026], array_map('intval', array_column($byDestinationName, 'cod_pessoa_transporte')));
        $this->assertSame([$link2026], array_map('intval', array_column($byDestinationId, 'cod_pessoa_transporte')));
    }

    public function test_detail_by_person_and_year_returns_the_link_of_that_year(): void
    {
        $person = $this->personRecord();
        $link2026 = $this->transportUserRecord($person, $this->routeRecord('Rota A ENS011', 2026));
        $this->transportUserRecord($person, $this->routeRecord('Rota A ENS011', 2027));

        $detail = (new \clsModulesPessoaTransporte(null, null, $person, null, null, null, null, 2026))->detalhe();
        $missing = (new \clsModulesPessoaTransporte(null, null, $person, null, null, null, null, 2030))->detalhe();

        $this->assertSame($link2026, (int) $detail['cod_pessoa_transporte']);
        $this->assertSame(2026, (int) $detail['ano']);
        $this->assertEmpty($missing);
    }

    public function test_edit_without_stop_and_destination_keeps_them(): void
    {
        $person = $this->personRecord();
        $route = $this->routeRecord('Rota A ENS011', 2026);
        $stop = $this->stopRecord('Ponto ENS011');
        $destination = $this->destinationRecord();
        $link = $this->transportUserRecord($person, $route, [
            'ref_cod_ponto_transporte_escolar' => $stop,
            'ref_idpes_destino' => $destination,
        ]);

        $record = new \clsModulesPessoaTransporte($link);
        $record->observacao = 'Nova observação';
        $record->edita();

        $saved = $this->transportUser($link);
        $this->assertSame($stop, (int) $saved->ref_cod_ponto_transporte_escolar);
        $this->assertSame($destination, (int) $saved->ref_idpes_destino);
        $this->assertSame('Nova observação', $saved->observacao);
    }

    public function test_create_requires_a_year(): void
    {
        $record = new \clsModulesPessoaTransporte(null, $this->routeRecord('Rota A ENS011', 2026), $this->personRecord());

        $this->assertFalse($record->cadastra());
    }
}
