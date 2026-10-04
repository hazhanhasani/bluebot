<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/client-routing.php';

function routingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$germanyA = 'vless://user-a@example.com:443?type=ws#Germany%20Premium%2001';
$germanyB = 'vless://user-b@example.net:443?type=ws#Frankfurt%20Backup%2002';
$netherlands = 'trojan://secret@example.org:443?security=tls#Netherlands%20Node';
$hidden = 'vless://user-d@example.edu:443?type=ws#secret-internal-node-9';

$locations = clientRoutingPublicLocations([$germanyA, $germanyB, $netherlands]);
routingAssert(count($locations) === 2, 'Duplicate country configs must collapse into one location.');
routingAssert($locations[0]['name'] === 'آلمان', 'Germany must be exposed only as the canonical location name.');
routingAssert($locations[0]['flag'] === '🇩🇪', 'Germany flag mismatch.');
routingAssert($locations[1]['name'] === 'هلند', 'Netherlands canonical location mismatch.');

$groups = clientRoutingGroups([$germanyA, $germanyB, $netherlands]);
routingAssert(count($groups[0]['links']) === 2, 'Germany group must keep both backend candidates.');
routingAssert(
    $groups[0]['index'] === clientRoutingLocationIndex('DE'),
    'Location index must be stable and derived from the canonical location key.'
);

$unknown = clientRoutingPublicLocations([$hidden]);
routingAssert($unknown[0]['name'] === 'سایر', 'Unknown raw config labels must never be exposed.');
routingAssert(
    !str_contains(json_encode($unknown, JSON_UNESCAPED_UNICODE), 'secret-internal-node-9'),
    'Raw config label leaked.'
);

$vmessPayload = base64_encode(json_encode([
    'v' => '2',
    'ps' => 'Germany VMess 03',
    'add' => 'example.com',
    'port' => '443',
], JSON_UNESCAPED_SLASHES));
$vmess = 'vmess://' . $vmessPayload;
$vmessMeta = clientRoutingLocationMeta($vmess);
routingAssert($vmessMeta['key'] === 'DE', 'VMess ps label must be canonicalized to Germany.');
$endpoint = clientRoutingLinkEndpoint($vmess);
routingAssert($endpoint === ['host' => 'example.com', 'port' => 443], 'VMess endpoint parsing failed.');

echo "Smart location routing tests passed.\n";
