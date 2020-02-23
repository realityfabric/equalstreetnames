<?php

declare(strict_types=1);

chdir(__DIR__.'/../');

require 'vendor/autoload.php';

$json = json_decode(file_get_contents('data/overpass/associatedStreet/full.json'), true);

$nodes = extractNodes($json['elements']);
$ways = extractWays($json['elements']);
$relations = extractRelations($json['elements']);

$waysInRelation = array_keys($ways);

$geojson = [
    'type'     => 'FeatureCollection',
    'features' => [],
];

foreach ($relations as $r) {
    $properties = [
        'name'      => $r['tags']['name'] ?? null,
        'name:fr'   => $r['tags']['name:fr'] ?? null,
        'name:nl'   => $r['tags']['name:nl'] ?? null,
        'wikidata'  => $r['tags']['wikidata'] ?? null,
        'etymology' => $r['tags']['name:etymology:wikidata'] ?? null,
    ];

    if (!is_null($properties['etymology'])) {
        $etymology = extractWikidata($properties['etymology']);

        if (!is_null($etymology['gender'])) {
            $properties = array_merge(
                $properties,
                [
                    'person' => $etymology,
                ]
            );
        }
    }

    $streets = array_filter(
        $r['members'],
        function ($member) {
            return $member['role'] === 'street';
        }
    );

    $linestrings = [];
    foreach ($streets as $street) {
        if (isset($ways[$street['ref']])) {
            $linestrings[] = appendCoordinates($nodes, $ways[$street['ref']]);
        } else {
            printf('Can\'t find way(%d) from relation(%d).%s', $street['ref'], $r['id'], PHP_EOL);
        }
    }

    if (count($linestrings) === 0) {
        printf('No geometry for relation(%d).%s', $r['id'], PHP_EOL);
    }

    $geojson['features'][] = [
        'type'       => 'Feature',
        'id'         => $r['id'],
        'properties' => $properties,
        'geometry'   => makeGeometry($linestrings),
    ];
}

file_put_contents('data/relations.geojson', json_encode($geojson));

unset($json, $geojson);

$json = json_decode(file_get_contents('data/overpass/highway/full.json'), true);

$nodes = extractNodes($json['elements']);
$ways = extractWays($json['elements']);

$geojson = [
    'type'     => 'FeatureCollection',
    'features' => [],
];

foreach ($ways as $w) {
    if (!in_array($w['id'], $waysInRelation)) {
        $properties = [
            'name'      => $w['tags']['name'] ?? null,
            'name:fr'   => $w['tags']['name:fr'] ?? null,
            'name:nl'   => $w['tags']['name:nl'] ?? null,
            'wikidata'  => $w['tags']['wikidata'] ?? null,
            'etymology' => $w['tags']['name:etymology:wikidata'] ?? null,
        ];

        if (!is_null($properties['etymology'])) {
            $etymology = extractWikidata($properties['etymology']);

            if (!is_null($etymology['gender'])) {
                $properties = array_merge(
                    $properties,
                    [
                        'person' => $etymology,
                    ]
                );
            }
        }

        $linestring = appendCoordinates($nodes, $w);

        $geojson['features'][] = [
            'type'       => 'Feature',
            'id'         => $w['id'],
            'properties' => $properties,
            'geometry'   => makeGeometry(is_null($linestrings) ? null : [$linestring]),
        ];
    }
}

file_put_contents('data/ways.geojson', json_encode($geojson));

exit(0);

function extractNodes(array $elements): array
{
    $filter = array_filter(
        $elements,
        function ($element) {
            return $element['type'] === 'node';
        }
    );

    $nodes = [];

    foreach ($filter as $f) {
        $nodes[$f['id']] = $f;
    }

    return $nodes;
}

function extractWays(array $elements): array
{
    $filter = array_filter(
        $elements,
        function ($element) {
            return $element['type'] === 'way';
        }
    );

    $ways = [];

    foreach ($filter as $f) {
        $ways[$f['id']] = $f;
    }

    return $ways;
}

function extractRelations(array $elements): array
{
    $filter = array_filter(
        $elements,
        function ($element) {
            return $element['type'] === 'relation';
        }
    );

    $relations = [];

    foreach ($filter as $f) {
        $relations[$f['id']] = $f;
    }

    return $relations;
}

function appendCoordinates(array $nodes, array $way): ?array
{
    $linestring = [];

    foreach ($way['nodes'] as $id) {
        $node = $nodes[$id] ?? null;

        if (is_null($node)) {
            printf('Can\'t find node(%d) in way(%d).%s', $id, $way['id'], PHP_EOL);
        } else {
            $linestring[] = [
                $node['lon'],
                $node['lat'],
            ];
        }
    }

    if (count($linestring) === 0) {
        printf('No geometry for way(%d).%s', $way['id'], PHP_EOL);
    }

    return count($linestring) === 0 ? null : $linestring;
}

function makeGeometry(array $linestrings): ?array
{
    if (count($linestrings) === 0) {
        return null;
    } elseif (count($linestrings) > 1) {
        return [
            'type'        => 'MultiLineString',
            'coordinates' => $linestrings,
        ];
    } else {
        return [
            'type'        => 'LineString',
            'coordinates' => $linestrings[0],
        ];
    }
}

function extractWikidata(string $identifier): ?array
{
    $path = sprintf('data/wikidata/%s.json', $identifier);

    if (!file_exists($path)) {
        printf('Missing file for %s.%s', $identifier, PHP_EOL);

        return null;
    }

    $json = json_decode(file_get_contents($path), true);

    $entity = $json['entities'][$identifier] ?? null;

    if (is_null($entity)) {
        printf('Entity %s missing in "%s".%s', $identifier, basename($path), PHP_EOL);

        return null;
    }

    $labels = array_filter(
        $entity['labels'],
        function ($language) {
            return in_array($language, ['de', 'en', 'fr', 'nl']);
        },
        ARRAY_FILTER_USE_KEY
    );

    $descriptions = array_filter(
        $entity['descriptions'],
        function ($language) {
            return in_array($language, ['de', 'en', 'fr', 'nl']);
        },
        ARRAY_FILTER_USE_KEY
    );

    $sitelinks = array_filter(
        $entity['sitelinks'],
        function ($language) {
            return in_array($language, ['dewiki', 'enwiki', 'frwiki', 'nlwiki']);
        },
        ARRAY_FILTER_USE_KEY
    );

    $genderId = $entity['claims']['P21'][0]['mainsnak']['datavalue']['value']['id'] ?? null;

    $image = $entity['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;

    $dateOfBirth = $entity['claims']['P569'][0]['mainsnak']['datavalue']['value']['time'] ?? null;
    $dateOfDeath = $entity['claims']['P570'][0]['mainsnak']['datavalue']['value']['time'] ?? null;

    return [
        'labels'       => $labels,
        'descriptions' => $descriptions,
        'gender'       => is_null($genderId) ? null : extractGender($genderId),
        'birth'        => is_null($dateOfBirth) ? null : intval(substr($dateOfBirth, 1, 4)),
        'death'        => is_null($dateOfDeath) ? null : intval(substr($dateOfDeath, 1, 4)),
        'image'        => is_null($image) ? null : sprintf('https://commons.wikimedia.org/wiki/File:%s', $image),
        'sitelinks'    => $sitelinks,
    ];
}

function extractGender(string $identifier): ?string
{
    $gender = null;

    switch ($identifier) {
        case 'Q6581097': // male
            return 'M';

        case 'Q6581072': // female
            return 'F';

        case 'Q1097630': // intersex
        case 'Q1052281': // transgender female
        case 'Q2449503': // transgender male
            return 'X';

        default:
            printf('Undefined gender %s.%s', $identifier, PHP_EOL);

            return null;
    }
}
