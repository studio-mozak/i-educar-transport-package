<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use App\Models\Place;
use clsModulesPontoTransporteEscolar;
use Database\Factories\LegacyUserFactory;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use iEducar\Packages\Transport\Tests\SendsStopApiRequests;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Api\DiarioApiRequestTestTrait;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';
require_once __DIR__ . '/../SendsStopApiRequests.php';

class StopApiCoordinatesTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions, DiarioApiRequestTestTrait, SendsStopApiRequests;

    public const STOP_API = '/module/Api/Ponto';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenControllerIsTaken('PontoController', \ApiCoreController::class);

        $_GET = [];
        $_POST = [];
    }

    public function test_post_saves_the_coordinate_typed_with_a_comma_normalized(): void
    {
        $response = $this->saveStop('post', ['latitude' => '-5,12345678', 'longitude' => '-42,8011'])
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $this->assertSame(['-5.123457', '-42.8011'], $this->stopCoordinates((int) $response->json('id')));
    }

    public function test_put_saves_the_coordinate_normalized(): void
    {
        $stop = $this->stopWithPlace();

        $this->saveStop('put', ['id' => (string) $stop, 'latitude' => '-5.091200', 'longitude' => '-42.837512'])
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $this->assertSame(['-5.0912', '-42.837512'], $this->stopCoordinates($stop));
    }

    public function test_put_with_both_fields_empty_clears_the_coordinate(): void
    {
        $stop = $this->stopWithPlace();

        $this->saveStop('put', ['id' => (string) $stop, 'latitude' => '', 'longitude' => ''])
            ->assertJsonPath('any_error_msg', false);

        $this->assertSame([null, null], $this->stopCoordinates($stop));
    }

    public function test_put_without_the_coordinate_fields_keeps_the_saved_coordinate(): void
    {
        $stop = $this->stopWithPlace('-5.091234', '-42.837512');

        $this->saveStop('put', ['id' => (string) $stop, 'desc' => 'Praça alterada ENS009'])
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $this->assertSame(['-5.091234', '-42.837512'], $this->stopCoordinates($stop));
        $this->assertSame('Praça alterada ENS009', DB::table('modules.ponto_transporte_escolar')->where('cod_ponto_transporte_escolar', $stop)->value('descricao'));
    }

    public function test_post_without_the_coordinate_fields_saves_the_stop_without_location(): void
    {
        $response = $this->saveStop('post', [])
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', false);

        $this->assertSame([null, null], $this->stopCoordinates((int) $response->json('id')));
    }

    public static function singleSentCoordinate(): array
    {
        return [
            'only latitude empty' => [['latitude' => '']],
            'only longitude empty' => [['longitude' => '']],
            'only latitude filled' => [['latitude' => '-5.0912']],
        ];
    }

    #[DataProvider('singleSentCoordinate')]
    public function test_put_with_only_one_coordinate_field_sent_changes_nothing(array $coordinate): void
    {
        $stop = $this->stopWithPlace();

        $this->saveStop('put', array_merge(['id' => (string) $stop, 'desc' => 'Praça alterada ENS009'], $coordinate))
            ->assertJsonPath('any_error_msg', true)
            ->assertJsonPath('msgs.0.msg', clsModulesPontoTransporteEscolar::COORDINATES_TOGETHER_MESSAGE);

        $this->assertSame(['-5.1', '-42.1'], $this->stopCoordinates($stop));
        $this->assertSame('Praça ENS009', DB::table('modules.ponto_transporte_escolar')->where('cod_ponto_transporte_escolar', $stop)->value('descricao'));
    }

    public static function invalidCoordinates(): array
    {
        return [
            'latitude out of range' => ['-95', '-42.8', [clsModulesPontoTransporteEscolar::LATITUDE_RANGE_MESSAGE]],
            'longitude out of range' => ['-5.09', '200', [clsModulesPontoTransporteEscolar::LONGITUDE_RANGE_MESSAGE]],
            'text' => ['abc', 'abc', [clsModulesPontoTransporteEscolar::LATITUDE_FORMAT_MESSAGE, clsModulesPontoTransporteEscolar::LONGITUDE_FORMAT_MESSAGE]],
            'only latitude' => ['-5.09', '', [clsModulesPontoTransporteEscolar::COORDINATES_TOGETHER_MESSAGE]],
        ];
    }

    #[DataProvider('invalidCoordinates')]
    public function test_post_with_an_invalid_coordinate_saves_nothing(string $latitude, string $longitude, array $messages): void
    {
        $stops = DB::table('modules.ponto_transporte_escolar')->count();
        $places = Place::query()->count();

        $response = $this->saveStop('post', ['latitude' => $latitude, 'longitude' => $longitude])
            ->assertSuccessful()
            ->assertJsonPath('any_error_msg', true)
            ->assertJsonMissingPath('id');

        $this->assertSame($messages, array_column($response->json('msgs'), 'msg'));
        $this->assertSame($stops, DB::table('modules.ponto_transporte_escolar')->count());
        $this->assertSame($places, Place::query()->count());
    }

    public function test_put_with_an_invalid_coordinate_changes_nothing(): void
    {
        $stop = $this->stopWithPlace();
        $before = (array) DB::table('modules.ponto_transporte_escolar')->where('cod_ponto_transporte_escolar', $stop)->first();
        $place = Place::query()->findOrFail($before['idlog'])->toArray();

        $this->saveStop('put', ['id' => (string) $stop, 'desc' => 'Praça alterada ENS009', 'address' => 'Rua alterada', 'latitude' => '', 'longitude' => '-42.2'])
            ->assertJsonPath('any_error_msg', true)
            ->assertJsonPath('msgs.0.msg', clsModulesPontoTransporteEscolar::COORDINATES_TOGETHER_MESSAGE);

        $this->assertSame($before, (array) DB::table('modules.ponto_transporte_escolar')->where('cod_ponto_transporte_escolar', $stop)->first());
        $this->assertSame($place, Place::query()->findOrFail($before['idlog'])->toArray());
    }

    public function test_get_returns_the_saved_coordinate(): void
    {
        $stop = $this->stopWithPlace('-5.091234', '-42.837512');
        $_GET = ['oper' => 'get', 'resource' => 'ponto', 'id' => (string) $stop];

        $this->actingAs(LegacyUserFactory::new()->admin()->create())
            ->get(self::STOP_API . '?' . http_build_query($_GET))
            ->assertJsonPath('latitude', '-5.091234')
            ->assertJsonPath('longitude', '-42.837512');
    }

    private function saveStop(string $operation, array $params): TestResponse
    {
        return $this->postResource(self::STOP_API, array_merge(
            ['oper' => $operation, 'resource' => 'ponto'],
            Arr::except($this->stopFields(), ['latitude', 'longitude']),
            $params,
        ));
    }
}
