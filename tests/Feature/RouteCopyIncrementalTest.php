<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use Database\Factories\LegacyUserFactory;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';

class RouteCopyIncrementalTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
    }

    public function test_first_copy_copies_every_route_with_its_itinerary(): void
    {
        $company = $this->companyRecord();
        $first = $this->routeRecord('Rota da Gameleira ENS016', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
        $second = $this->routeRecord('Rota do Sertão ENS016', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
        $this->itineraryStopRecord($first, 1, $this->stopRecord('Fazenda ENS016'), null, '06:30', 'I');
        $this->itineraryStopRecord($first, 2, $this->stopRecord('Escola ENS016'), null, '07:00', 'I');
        $this->itineraryStopRecord($second, 1, $this->stopRecord('Praça ENS016'), null, '11:30', 'V');

        $this->assertCopyResult($this->copyRoutes($company), 'success', '2 rotas copiadas.');

        $copies = $this->routesOf($company, 2027);
        $this->assertSame(['Rota da Gameleira ENS016', 'Rota do Sertão ENS016'], array_keys($copies));
        $this->assertSame($this->itinerary($first), $this->itinerary($copies['Rota da Gameleira ENS016']));
        $this->assertSame($this->itinerary($second), $this->itinerary($copies['Rota do Sertão ENS016']));
    }

    public function test_copying_again_copies_only_the_routes_missing_in_the_target_year(): void
    {
        $company = $this->companyRecord();
        $this->routesCopiedBefore($company);
        $newRoute = $this->routeRecord('Rota nova ENS016', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
        $this->itineraryStopRecord($newRoute, 1, $this->stopRecord('Ponto novo ENS016'), null, '06:15', 'I');
        $copiesBefore = $this->routesOf($company, 2027);

        $this->assertCopyResult(
            $this->copyRoutes($company),
            'success',
            '1 rota copiada. 2 já existiam em 2027 e não foram copiadas.'
        );

        $copies = $this->routesOf($company, 2027);
        $this->assertCount(3, $copies);
        $this->assertSame($copiesBefore['Rota da Gameleira ENS016'], $copies['Rota da Gameleira ENS016']);
        $this->assertSame($copiesBefore['Rota do Sertão ENS016'], $copies['Rota do Sertão ENS016']);
        $this->assertSame($this->itinerary($newRoute), $this->itinerary($copies['Rota nova ENS016']));
    }

    public function test_copying_again_without_new_routes_copies_nothing_and_shows_an_error(): void
    {
        $company = $this->companyRecord();
        $this->routesCopiedBefore($company);

        $this->assertCopyResult($this->copyRoutes($company), 'error', 'Nenhuma rota copiada: todas já existiam em 2027.');

        $this->assertCount(2, $this->routesOf($company, 2027));
    }

    public function test_route_names_are_compared_without_case_and_surrounding_spaces(): void
    {
        $company = $this->companyRecord();
        $this->routeRecord('Rota da Gameleira ENS016', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
        $this->routeRecord('Rota do Sertão ENS016', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
        $this->routeRecord('Rota da Várzea ENS016', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
        $this->routeRecord('  ROTA DA GAMELEIRA ens016 ', 2027, ['ref_cod_empresa_transporte_escolar' => $company]);

        $this->assertCopyResult(
            $this->copyRoutes($company),
            'success',
            '2 rotas copiadas. 1 já existia em 2027 e não foi copiada.'
        );

        $this->assertSame(
            ['  ROTA DA GAMELEIRA ens016 ', 'Rota da Várzea ENS016', 'Rota do Sertão ENS016'],
            array_keys($this->routesOf($company, 2027))
        );
    }

    public function test_routes_of_another_company_or_year_do_not_block_the_copy(): void
    {
        $company = $this->companyRecord();
        $this->routeRecord('Rota da Gameleira ENS016', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
        $this->routeRecord('Rota da Gameleira ENS016', 2027, ['ref_cod_empresa_transporte_escolar' => $this->companyRecord()]);
        $this->routeRecord('Rota da Gameleira ENS016', 2028, ['ref_cod_empresa_transporte_escolar' => $company]);

        $this->assertCopyResult($this->copyRoutes($company), 'success', '1 rota copiada.');

        $this->assertCount(1, $this->routesOf($company, 2027));
    }

    public static function resultMessages(): array
    {
        return [
            'one copied, one skipped' => [['Rota A ENS016', 'Rota B ENS016'], ['Rota A ENS016'], 'success', '1 rota copiada. 1 já existia em 2027 e não foi copiada.'],
            'three copied, one skipped' => [['Rota A ENS016', 'Rota B ENS016', 'Rota C ENS016', 'Rota D ENS016'], ['Rota A ENS016'], 'success', '3 rotas copiadas. 1 já existia em 2027 e não foi copiada.'],
            'none copied, the only route existed' => [['Rota A ENS016'], ['Rota A ENS016'], 'error', 'Nenhuma rota copiada: a rota já existia em 2027.'],
        ];
    }

    #[DataProvider('resultMessages')]
    public function test_result_message_agrees_with_the_counts(array $sourceNames, array $targetNames, string $style, string $message): void
    {
        $company = $this->companyRecord();

        foreach ($sourceNames as $name) {
            $this->routeRecord($name, 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
        }

        foreach ($targetNames as $name) {
            $this->routeRecord($name, 2027, ['ref_cod_empresa_transporte_escolar' => $company]);
        }

        $this->assertCopyResult($this->copyRoutes($company), $style, $message);
    }

    public function test_source_year_without_routes_keeps_the_current_message(): void
    {
        $this->assertCopyResult(
            $this->copyRoutes($this->companyRecord()),
            'error',
            'Não existe rotas em 2026 para essa empresa'
        );
    }

    public function test_invalid_target_year_is_rejected(): void
    {
        $company = $this->companyRecord();
        $this->routeRecord('Rota da Gameleira ENS016', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);

        $response = $this->actingAs(LegacyUserFactory::new()->admin()->create())
            ->post('/intranet/transporte_copia_rotas.php', [
                'tipoacao' => 'Novo',
                'ref_cod_empresa_transporte_escolar' => (string) $company,
                'ano_orig' => 2026,
                'ano_dest' => '2027<b>',
            ]);

        $this->assertCopyResult($response, 'error', 'Preencha os dados corretamente.<br>');
        $this->assertSame(1, DB::table('modules.rota_transporte_escolar')->where('ref_cod_empresa_transporte_escolar', $company)->count());
    }

    public function test_invalid_company_is_rejected(): void
    {
        $company = $this->companyRecord();
        $this->routeRecord('Rota da Gameleira ENS016', 2026, ['ref_cod_empresa_transporte_escolar' => $company]);

        $response = $this->actingAs(LegacyUserFactory::new()->admin()->create())
            ->post('/intranet/transporte_copia_rotas.php', [
                'tipoacao' => 'Novo',
                'ref_cod_empresa_transporte_escolar' => "1' OR '1'='1",
                'ano_orig' => 2026,
                'ano_dest' => 2027,
            ]);

        $this->assertCopyResult($response, 'error', 'Preencha os dados corretamente.<br>');
        $this->assertSame(0, DB::table('modules.rota_transporte_escolar')->where('ano', 2027)->where('descricao', 'Rota da Gameleira ENS016')->count());
    }

    public static function interruptedCopies(): array
    {
        return [
            'failure in the middle' => ['Rota B ENS016', ['Rota A ENS016'], 'Não foi possível copiar a rota "Rota B ENS016". 1 rota copiada antes do erro; copie de novo para completar.'],
            'failure after two copies' => ['Rota C ENS016', ['Rota A ENS016', 'Rota B ENS016'], 'Não foi possível copiar a rota "Rota C ENS016". 2 rotas copiadas antes do erro; copie de novo para completar.'],
            'failure in the first route' => ['Rota A ENS016', [], 'Não foi possível copiar a rota "Rota A ENS016". Nenhuma rota copiada; tente de novo.'],
        ];
    }

    #[DataProvider('interruptedCopies')]
    public function test_failed_route_is_undone_and_the_copy_stops(string $failingName, array $copiedNames, string $message): void
    {
        $company = $this->companyRecord();
        $sourceRoutes = [];

        foreach (['Rota A ENS016', 'Rota B ENS016', 'Rota C ENS016'] as $name) {
            $sourceRoutes[$name] = $this->routeRecord($name, 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
            $this->itineraryStopRecord($sourceRoutes[$name], 1, $this->stopRecord('Fazenda ' . $name), null, '06:30', 'I');
        }

        $failingStop = $this->stopRecord('Ponto que falha ENS016');
        $this->itineraryStopRecord($sourceRoutes[$failingName], 2, $failingStop, null, '07:00', 'I');
        $this->failInsertsOfStop($failingStop);

        $this->assertCopyResult($this->copyRoutes($company), 'error', $message);

        $copies = $this->routesOf($company, 2027);
        $this->assertSame($copiedNames, array_keys($copies));

        foreach ($copiedNames as $name) {
            $this->assertSame($this->itinerary($sourceRoutes[$name]), $this->itinerary($copies[$name]));
        }
    }

    private function failInsertsOfStop(int $stop): void
    {
        DB::unprepared("
            CREATE FUNCTION public.ens016_fail_stop_insert() RETURNS trigger AS $$
            BEGIN
                IF NEW.ref_cod_ponto_transporte_escolar = {$stop} THEN
                    RAISE EXCEPTION 'simulated itinerary failure';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER ens016_fail_stop_insert BEFORE INSERT ON modules.itinerario_transporte_escolar
                FOR EACH ROW EXECUTE FUNCTION public.ens016_fail_stop_insert();
        ");
    }

    private function assertCopyResult(TestResponse $response, string $style, string $message): void
    {
        $otherStyle = $style === 'success' ? 'error' : 'success';
        $content = $response->assertSuccessful()->getContent();

        $this->assertMatchesRegularExpression(
            '/<div class="flashMessages__message -' . $style . '">.*?<\/time>' . preg_quote($message, '/') . '<\/div>/s',
            $content
        );
        $this->assertStringNotContainsString('flashMessages__message -' . $otherStyle, $content);
    }

    private function routesCopiedBefore(int $company): void
    {
        foreach (['Rota da Gameleira ENS016', 'Rota do Sertão ENS016'] as $name) {
            $this->routeRecord($name, 2026, ['ref_cod_empresa_transporte_escolar' => $company]);
            $this->routeRecord($name, 2027, ['ref_cod_empresa_transporte_escolar' => $company]);
        }
    }

    private function routesOf(int $company, int $year): array
    {
        return DB::table('modules.rota_transporte_escolar')
            ->where('ref_cod_empresa_transporte_escolar', $company)
            ->where('ano', $year)
            ->pluck('cod_rota_transporte_escolar', 'descricao')
            ->map(fn ($route) => (int) $route)
            ->sortKeys(SORT_STRING)
            ->all();
    }
}
