<?php

namespace iEducar\Packages\Transport\Tests\Feature;

use clsModulesPontoTransporteEscolar;
use iEducar\Packages\Transport\Tests\CreatesItineraryRecords;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once __DIR__ . '/../CreatesItineraryRecords.php';

class StopCoordinatesTest extends TestCase
{
    use CreatesItineraryRecords, DatabaseTransactions;

    public static function validCoordinates(): array
    {
        return [
            'comma' => ['-5,0912', '-42,8011', '-5.0912', '-42.8011'],
            'dot' => ['-5.0912', '-42.8011', '-5.0912', '-42.8011'],
            'six decimals' => ['-5.12345678', '-42.1234564', '-5.123457', '-42.123456'],
            'trailing zeros' => ['-5.091200', '-42.800000', '-5.0912', '-42.8'],
            'spaces around' => [' -5.0912 ', ' -42.8011', '-5.0912', '-42.8011'],
            'integer' => ['-5', '42', '-5', '42'],
            'negative zero' => ['-0.0000001', '-0', '0', '0'],
            'limits' => ['-90', '180', '-90', '180'],
        ];
    }

    #[DataProvider('validCoordinates')]
    public function test_valid_coordinates_are_normalized(string $latitude, string $longitude, string $expectedLatitude, string $expectedLongitude): void
    {
        $this->assertSame(
            ['latitude' => $expectedLatitude, 'longitude' => $expectedLongitude, 'errors' => []],
            clsModulesPontoTransporteEscolar::coordinatesFromInput($latitude, $longitude)
        );
    }

    public static function invalidCoordinates(): array
    {
        return [
            'latitude out of range' => ['-95', '-42.8', ['latitude' => clsModulesPontoTransporteEscolar::LATITUDE_RANGE_MESSAGE]],
            'longitude out of range' => ['-5.09', '200', ['longitude' => clsModulesPontoTransporteEscolar::LONGITUDE_RANGE_MESSAGE]],
            'both out of range' => ['-95', '200', [
                'latitude' => clsModulesPontoTransporteEscolar::LATITUDE_RANGE_MESSAGE,
                'longitude' => clsModulesPontoTransporteEscolar::LONGITUDE_RANGE_MESSAGE,
            ]],
            'text' => ['abc', '-42.8', ['latitude' => clsModulesPontoTransporteEscolar::LATITUDE_FORMAT_MESSAGE]],
            'exponent' => ['1e3', '-42.8', ['latitude' => clsModulesPontoTransporteEscolar::LATITUDE_FORMAT_MESSAGE]],
            'plus sign' => ['+5', '-42.8', ['latitude' => clsModulesPontoTransporteEscolar::LATITUDE_FORMAT_MESSAGE]],
            'two separators' => ['-5.09', '-42,8.1', ['longitude' => clsModulesPontoTransporteEscolar::LONGITUDE_FORMAT_MESSAGE]],
            'only latitude' => ['-5.09', '', ['longitude' => clsModulesPontoTransporteEscolar::COORDINATES_TOGETHER_MESSAGE]],
            'only longitude' => [null, '-42.8', ['latitude' => clsModulesPontoTransporteEscolar::COORDINATES_TOGETHER_MESSAGE]],
            'array' => [['-5'], '-42.8', ['latitude' => clsModulesPontoTransporteEscolar::LATITUDE_FORMAT_MESSAGE]],
        ];
    }

    #[DataProvider('invalidCoordinates')]
    public function test_invalid_coordinates_are_refused_with_the_field_message(mixed $latitude, mixed $longitude, array $errors): void
    {
        $this->assertSame(
            ['latitude' => null, 'longitude' => null, 'errors' => $errors],
            clsModulesPontoTransporteEscolar::coordinatesFromInput($latitude, $longitude)
        );
    }

    public function test_both_empty_clear_the_coordinates(): void
    {
        $this->assertSame(['latitude' => null, 'longitude' => null, 'errors' => []], clsModulesPontoTransporteEscolar::coordinatesFromInput(' ', null));
    }

    public function test_normalized_coordinate_uses_the_given_limit(): void
    {
        $this->assertSame('120.5', clsModulesPontoTransporteEscolar::normalizedCoordinate('120,50'));
        $this->assertNull(clsModulesPontoTransporteEscolar::normalizedCoordinate('120.5', clsModulesPontoTransporteEscolar::LATITUDE_LIMIT));
        $this->assertNull(clsModulesPontoTransporteEscolar::normalizedCoordinate(''));
        $this->assertNull(clsModulesPontoTransporteEscolar::normalizedCoordinate(null));
    }

    public function test_update_coordinates_saves_and_clears_both_columns(): void
    {
        $stop = $this->stopRecord('Praça ENS009', ['latitude' => '-5.1', 'longitude' => '-42.1']);
        $record = new clsModulesPontoTransporteEscolar($stop);

        $this->assertTrue($record->updateCoordinates('-5.091234', '-42.837512'));
        $this->assertSame(['-5.091234', '-42.837512'], $this->stopCoordinates($stop));

        $this->assertTrue($record->updateCoordinates(null, null));
        $this->assertSame([null, null], $this->stopCoordinates($stop));

        $this->assertFalse((new clsModulesPontoTransporteEscolar)->updateCoordinates('-5', '-42'));
    }
}
