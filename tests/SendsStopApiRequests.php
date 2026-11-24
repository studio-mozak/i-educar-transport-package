<?php

namespace iEducar\Packages\Transport\Tests;

use App\Models\LegacyUser;
use Database\Factories\CityFactory;
use Database\Factories\LegacyMenuUserTypeFactory;
use Database\Factories\LegacyUserFactory;
use Database\Factories\PlaceFactory;
use Illuminate\Testing\TestResponse;

trait SendsStopApiRequests
{
    protected function stopFields(): array
    {
        return [
            'desc' => 'Praça ENS009',
            'postal_code' => '65630-000',
            'address' => 'Rua da Praça',
            'number' => '10',
            'neighborhood' => 'Centro',
            'city_id' => (string) CityFactory::new()->create()->getKey(),
            'latitude' => '-5.0912',
            'longitude' => '-42.8011',
        ];
    }

    protected function stopApiRequest(LegacyUser $user, string $operation, array $params): TestResponse
    {
        $_POST = array_merge(['oper' => $operation, 'resource' => 'ponto'], $params);

        return $this->actingAs($user)->post('/module/Api/Ponto?' . http_build_query($_POST));
    }

    protected function stopWithPlace(string $latitude = '-5.1', string $longitude = '-42.1'): int
    {
        $place = PlaceFactory::new()->create()->getKey();

        return $this->stopRecord('Praça ENS009', ['idlog' => $place, 'idbai' => $place, 'latitude' => $latitude, 'longitude' => $longitude]);
    }

    protected function userWithMenuFlags(array $flagsByMenuId): LegacyUser
    {
        $user = LegacyUserFactory::new()->institutional()->create();

        foreach (array_filter($flagsByMenuId) as $menuId => $flags) {
            LegacyMenuUserTypeFactory::new()->create(array_merge($flags, [
                'menu_id' => $menuId,
                'ref_cod_tipo_usuario' => $user->ref_cod_tipo_usuario,
            ]));
        }

        return $user;
    }
}
