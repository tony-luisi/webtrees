<?php
/**
 * webtrees: online genealogy
 * Copyright (C) 2018 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Fisharebest\Webtrees\Module;

use Exception;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Database;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\FactLocation;
use Fisharebest\Webtrees\Functions\Functions;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\View;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class PlacesMapModule
 */
class PlacesModule extends AbstractModule implements ModuleTabInterface
{
    private static $map_providers  = null;
    private static $map_selections = null;

    /** {@inheritdoc} */
    public function getTitle()
    {
        return /* I18N: Name of a module */
            I18N::translate('Places');
    }

    /** {@inheritdoc} */
    public function getDescription()
    {
        return /* I18N: Description of the “OSM” module */
            I18N::translate('Show the location of events on a map.');
    }

    /** {@inheritdoc} */
    public function defaultAccessLevel()
    {
        return Auth::PRIV_PRIVATE;
    }

    /** {@inheritdoc} */
    public function defaultTabOrder()
    {
        return 4;
    }

    /** {@inheritdoc} */
    public function hasTabContent(Individual $individual)
    {
        return true;
    }

    /** {@inheritdoc} */
    public function isGrayedOut(Individual $individual)
    {
        return false;
    }

    /** {@inheritdoc} */
    public function canLoadAjax()
    {
        return true;
    }

    /** {@inheritdoc} */
    public function getTabContent(Individual $individual)
    {
        return view('modules/places/tab', [
            'data' => $this->getMapData($individual),
        ]);
    }

    /**
     * @param Request $request
     *
     * @return stdClass
     */
    private function getMapData(Individual $indi): stdClass
    {
        $facts = $this->getPersonalFacts($indi);

        $geojson = [
            'type'     => 'FeatureCollection',
            'features' => [],
        ];

        foreach ($facts as $id => $fact) {
            $event = new FactLocation($fact, $indi);
            $icon  = $event->getIconDetails();
            if ($event->knownLatLon()) {
                $geojson['features'][] = [
                    'type'       => 'Feature',
                    'id'         => $id,
                    'valid'      => true,
                    'geometry'   => [
                        'type'        => 'Point',
                        'coordinates' => $event->getGeoJsonCoords(),
                    ],
                    'properties' => [
                        'polyline' => null,
                        'icon'     => $icon,
                        'tooltip'  => $event->toolTip(),
                        'summary'  => view(
                            'modules/places/event-sidebar',
                            $event->shortSummary('individual', $id)
                        ),
                        'zoom'     => (int) $event->getZoom(),
                    ],
                ];
            }
        }

        return (object) $geojson;
    }

    /**
     * @param Individual $individual
     *
     * @return array
     * @throws Exception
     */
    private function getPersonalFacts(Individual $individual)
    {
        $facts      = $individual->getFacts();
        foreach ($individual->getSpouseFamilies() as $family) {
            $facts = array_merge($facts, $family->getFacts());
            // Add birth of children from this family to the facts array
            foreach ($family->getChildren() as $child) {
                $childsBirth = $child->getFirstFact('BIRT');
                if ($childsBirth && !$childsBirth->getPlace()->isEmpty()) {
                    $facts[] = $childsBirth;
                }
            }
        }

        Functions::sortFacts($facts);

        $useable_facts = array_filter(
            $facts,
            function (Fact $item) {
                return !$item->getPlace()->isEmpty();
            }
        );

        return array_values($useable_facts);
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getProviderStylesAction(Request $request): JsonResponse
    {
        $styles = $this->getMapProviderData($request);

        return new JsonResponse($styles);
    }

    /**
     * @param Request $request
     *
     * @return array|null
     */
    private function getMapProviderData(Request $request)
    {
        if (self::$map_providers === null) {
            $providersFile        = WT_ROOT . WT_MODULES_DIR . 'openstreetmap/providers/providers.xml';
            self::$map_selections = [
                'provider' => $this->getPreference('provider', 'openstreetmap'),
                'style'    => $this->getPreference('provider_style', 'mapnik'),
            ];

            try {
                $xml = simplexml_load_file($providersFile);
                // need to convert xml structure into arrays & strings
                foreach ($xml as $provider) {
                    $style_keys = array_map(
                        function ($item) {
                            return preg_replace('/[^a-z\d]/i', '', strtolower($item));
                        },
                        (array) $provider->styles
                    );

                    $key = preg_replace('/[^a-z\d]/i', '', strtolower((string) $provider->name));

                    self::$map_providers[$key] = [
                        'name'   => (string) $provider->name,
                        'styles' => array_combine($style_keys, (array) $provider->styles),
                    ];
                }
            } catch (Exception $ex) {
                // Default provider is OpenStreetMap
                self::$map_selections = [
                    'provider' => 'openstreetmap',
                    'style'    => 'mapnik',
                ];
                self::$map_providers  = [
                    'openstreetmap' => [
                        'name'   => 'OpenStreetMap',
                        'styles' => ['mapnik' => 'Mapnik'],
                    ],
                ];
            };
        }

        //Ugly!!!
        switch ($request->get('action')) {
            case 'BaseData':
                $varName = (self::$map_selections['style'] === '') ? '' : self::$map_providers[self::$map_selections['provider']]['styles'][self::$map_selections['style']];
                $payload = [
                    'selectedProvIndex' => self::$map_selections['provider'],
                    'selectedProvName'  => self::$map_providers[self::$map_selections['provider']]['name'],
                    'selectedStyleName' => $varName,
                ];
                break;
            case 'ProviderStyles':
                $provider = $request->get('provider', 'openstreetmap');
                $payload  = self::$map_providers[$provider]['styles'];
                break;
            case 'AdminConfig':
                $providers = [];
                foreach (self::$map_providers as $key => $provider) {
                    $providers[$key] = $provider['name'];
                }
                $payload = [
                    'providers'     => $providers,
                    'selectedProv'  => self::$map_selections['provider'],
                    'styles'        => self::$map_providers[self::$map_selections['provider']]['styles'],
                    'selectedStyle' => self::$map_selections['style'],
                ];
                break;
            default:
                $payload = null;
        }

        return $payload;
    }

    /**
     * @param $parent_id
     * @param $placename
     * @param $places
     *
     * @throws Exception
     */
    private function buildLevel($parent_id, $placename, &$places)
    {
        $level = array_search('', $placename);
        $rows  = (array) Database::prepare(
            "SELECT pl_level, pl_id, pl_place, pl_long, pl_lati, pl_zoom, pl_icon FROM `##placelocation` WHERE pl_parent_id=? ORDER BY pl_place"
        )
            ->execute([$parent_id])
            ->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            foreach ($rows as $row) {
                $index             = $row['pl_id'];
                $placename[$level] = $row['pl_place'];
                $places[]          = array_merge([$row['pl_level']], $placename, array_splice($row, 3));
                $this->buildLevel($index, $placename, $places);
            }
        }
    }
}
