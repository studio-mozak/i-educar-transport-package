<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use iEducar\Packages\Transport\Database\TransportUserYearDuplicates;
use iEducar\Packages\Transport\Tests\CreatesTransportUserRecords;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesTransportUserRecords.php';

class TransportUserYearIndexMigrationTest extends TestCase
{
    use CreatesTransportUserRecords, DatabaseTransactions;

    private const MIGRATION = __DIR__ . '/../../database/migrations/2026_11_18_100000_allow_many_transport_links_per_person_and_year.php';

    public function test_migrated_database_has_no_unique_index_for_person_and_year(): void
    {
        $this->assertFalse($this->hasUniqueIndex());
    }

    public function test_rollback_recreates_the_unique_index_when_no_person_has_many_links_in_a_year(): void
    {
        $this->removeExistingManyLinksInTheSameYear();
        $person = $this->personRecord();
        $this->transportUserRecord($person, $this->routeRecord('Rota A ENS016', 2026));
        $this->transportUserRecord($person, $this->routeRecord('Rota A ENS016', 2027));

        $this->migration()->down();

        $this->assertTrue($this->hasUniqueIndex());

        $this->migration()->up();

        $this->assertFalse($this->hasUniqueIndex());
    }

    public function test_rollback_is_aborted_while_a_person_has_many_links_in_the_same_year(): void
    {
        $person = $this->personRecord();
        $first = $this->transportUserRecord($person, $this->routeRecord('Rota A ENS016', 2026));
        $second = $this->transportUserRecord($person, $this->routeRecord('Rota B ENS016', 2026));

        try {
            $this->migration()->down();
            $this->fail('The rollback should be aborted.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith(
                'Existem pessoas com mais de um vínculo de transporte no mesmo ano.',
                $exception->getMessage()
            );
            $this->assertStringContainsString("pessoa {$person}, ano 2026: vínculos {$first}, {$second}", $exception->getMessage());
        }

        $this->assertFalse($this->hasUniqueIndex());
    }

    private function removeExistingManyLinksInTheSameYear(): void
    {
        DB::statement('
            DELETE FROM modules.pessoa_transporte
            WHERE (ref_idpes, ano) IN (
                SELECT ref_idpes, ano
                FROM modules.pessoa_transporte
                GROUP BY ref_idpes, ano
                HAVING COUNT(*) > 1
            )
        ');
    }

    private function hasUniqueIndex(): bool
    {
        return DB::table('pg_indexes')
            ->where('schemaname', 'modules')
            ->where('indexname', TransportUserYearDuplicates::UNIQUE_INDEX)
            ->exists();
    }

    private function migration(): Migration
    {
        return require self::MIGRATION;
    }
}
