<?php

/**
 * MapquestGeocodeApi.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2018 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\ApiClients;

use App\Facades\LibrenmsConfig;
use Exception;
use Illuminate\Http\Client\Response;
use LibreNMS\Interfaces\Geocoder;

class MapquestApi extends BaseApi implements Geocoder
{
    use GeocodingHelper;

    protected string $base_uri = 'https://open.mapquestapi.com';
    protected string $geocoding_uri = '/geocoding/v1/address';

    /**
     * Get latitude and longitude from geocode response
     */
    protected function parseLatLng(array $data): array
    {
        return [
            'lat' => isset($data['results'][0]['locations'][0]['latLng']['lat']) ? $data['results'][0]['locations'][0]['latLng']['lat'] : 0,
            'lng' => isset($data['results'][0]['locations'][0]['latLng']['lng']) ? $data['results'][0]['locations'][0]['latLng']['lng'] : 0,
        ];
    }

    /**
     * Build request option array
     *
     * @throws Exception you may throw an Exception if validation fails
     */
    protected function buildGeocodingOptions(string $address): array
    {
        $api_key = LibrenmsConfig::get('geoloc.api_key');
        if (! $api_key) {
            throw new Exception('MapQuest API key missing, set geoloc.api_key');
        }

        return [
            'query' => [
                'key' => $api_key,
                'location' => $address,
                'thumbMaps' => 'false',
            ],
        ];
    }

    /**
     * Checks if the request was a success
     */
    protected function checkResponse(Response $response, array $data): bool
    {
        return $response->successful() && $data['info']['statuscode'] == 0;
    }
}
