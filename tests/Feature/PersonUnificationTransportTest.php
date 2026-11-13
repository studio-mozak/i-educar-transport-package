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

    public function test_keeps_only_the_most_recent_link_when_both_people_have_one_in_the_same_year(): void
    {
        $main = $this->personRecord();
        $duplicate = $this->personRecord();
        $olderLink = $this->transportUserRecord($main, $this->routeRecord('Rota A ENS011', 2026));
        $newerLink = $this->transportUserRecord($duplicate, $this->routeRecord('Rota B ENS011', 2026));
        $otherYearLink = $this->transportUserRecord($duplicate, $this->routeRecord('Rota C ENS011', 2025));

        $this->unify($main, $duplicate);

        $this->assertDatabaseMissing('modules.pessoa_transporte', ['cod_pessoa_transporte' => $olderLink]);
        $this->assertSame($main, (int) $this->transportUser($newerLink)->ref_idpes);
        $this->assertSame($main, (int) $this->transportUser($otherYearLink)->ref_idpes);
        $this->assertSame(2, $this->transportUserCount($main));

        $log = LogUnification::query()->where('main_id', $main)->where('active', true)->firstOrFail();
        $removedLinkLog = LogUnificationOldData::query()
            ->where('unification_id', $log->getKey())
            ->where('table', 'modules.pessoa_transporte')
            ->get()
            ->first(fn ($oldData) => $oldData->keys[0] === ['cod_pessoa_transporte' => $olderLink]);

        $this->assertNotNull($removedLinkLog);
        $this->assertSame($olderLink, (int) $removedLinkLog->old_data[0]['cod_pessoa_transporte']);
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
