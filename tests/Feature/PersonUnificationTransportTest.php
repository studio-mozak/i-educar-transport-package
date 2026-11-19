<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use App\Models\LogUnification;
use App\Models\LogUnificationOldData;
use iEducar\Packages\Transport\Tests\CreatesTransportUserRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\LoginFirstUser;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportUserRecords.php';

class PersonUnificationTransportTest extends TestCase
{
    use CreatesTransportUserRecords, DatabaseTransactions, LoginFirstUser;

    public function test_keeps_links_of_different_years_under_the_main_person(): void
    {
        $main = $this->personRecord();
        $duplicate = $this->personRecord();
        $mainLink = $this->transportUserRecord($main, $this->routeRecord('Rota A ENS011', 2025));
        $duplicateLink = $this->transportUserRecord($duplicate, $this->routeRecord('Rota B ENS011', 2026));

        $this->unify($main, $duplicate);

        $this->assertSame($main, (int) $this->transportUser($mainLink)->ref_idpes);
        $this->assertSame($main, (int) $this->transportUser($duplicateLink)->ref_idpes);
        $this->assertSame(0, $this->transportUserCount($duplicate));
    }

    public function test_keeps_every_link_of_the_same_year_under_the_main_person(): void
    {
        $main = $this->personRecord();
        $duplicate = $this->personRecord();
        $route = $this->routeRecord('Rota A ENS016', 2026);
        $stop = $this->stopRecord('Ponto ENS016');
        $mainLink = $this->transportUserRecord($main, $route, ['ref_cod_ponto_transporte_escolar' => $stop, 'turno' => '1']);
        $otherPeriodLink = $this->transportUserRecord($duplicate, $route, ['ref_cod_ponto_transporte_escolar' => $stop, 'turno' => '2']);
        $otherStopLink = $this->transportUserRecord($duplicate, $route, ['turno' => '1']);
        $otherRouteLink = $this->transportUserRecord($duplicate, $this->routeRecord('Rota B ENS016', 2026));

        $this->unify($main, $duplicate);

        foreach ([$mainLink, $otherPeriodLink, $otherStopLink, $otherRouteLink] as $link) {
            $this->assertSame($main, (int) $this->transportUser($link)->ref_idpes);
        }

        $this->assertSame(4, $this->transportUserCount($main));
        $this->assertSame(0, $this->transportUserCount($duplicate));
    }

    public function test_keeps_a_single_link_when_both_people_have_identical_links(): void
    {
        $main = $this->personRecord();
        $duplicate = $this->personRecord();
        $route = $this->routeRecord('Rota A ENS016', 2026);
        $stop = $this->stopRecord('Ponto ENS016');
        $destination = $this->destinationRecord();
        $identical = ['ref_cod_ponto_transporte_escolar' => $stop, 'ref_idpes_destino' => $destination, 'turno' => '1'];
        $keptLink = $this->transportUserRecord($main, $route, $identical);
        $repeatedLink = $this->transportUserRecord($duplicate, $route, $identical);
        $withoutStopLink = $this->transportUserRecord($main, $route);
        $repeatedWithoutStopLink = $this->transportUserRecord($duplicate, $route);

        $this->unify($main, $duplicate);

        $this->assertDatabaseMissing('modules.pessoa_transporte', ['cod_pessoa_transporte' => $repeatedLink]);
        $this->assertDatabaseMissing('modules.pessoa_transporte', ['cod_pessoa_transporte' => $repeatedWithoutStopLink]);
        $this->assertSame($main, (int) $this->transportUser($keptLink)->ref_idpes);
        $this->assertSame($main, (int) $this->transportUser($withoutStopLink)->ref_idpes);
        $this->assertSame(2, $this->transportUserCount($main));

        $log = LogUnification::query()->where('main_id', $main)->where('active', true)->firstOrFail();
        $removedLinkLog = LogUnificationOldData::query()
            ->where('unification_id', $log->getKey())
            ->where('table', 'modules.pessoa_transporte')
            ->get()
            ->first(fn ($oldData) => $oldData->keys[0] === ['cod_pessoa_transporte' => $repeatedLink]);

        $this->assertNotNull($removedLinkLog);
        $this->assertSame($repeatedLink, (int) $removedLinkLog->old_data[0]['cod_pessoa_transporte']);
    }

    private function unify(int $main, int $duplicate): void
    {
        $this->post('/intranet/educar_unifica_pessoa.php', [
            'tipoacao' => 'Novo',
            'pessoas' => collect([
                ['idpes' => $main, 'pessoa_principal' => true],
                ['idpes' => $duplicate, 'pessoa_principal' => false],
            ]),
        ])->assertSuccessful()->assertSee('Pessoas unificadas com sucesso.');
    }
}
