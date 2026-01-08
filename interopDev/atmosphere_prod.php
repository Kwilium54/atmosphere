<?php
/**
 * Configuration du proxy pour le serveur webetu de l'université
 * Ce proxy est nécessaire pour accéder aux APIs externes depuis le réseau universitaire
 * En local, ces paramètres sont ignorés car non nécessaires
 */
$opts = array(
    'http' => array(
        'proxy' => 'tcp://www-cache:3128',
        'request_fulluri' => true
    ),
    'ssl' => array(
        'verify_peer' => false,
        'verify_peer_name' => false
    )
);
$context = stream_context_create($opts);
stream_context_set_default($opts);

// ============================================
// FONCTIONS UTILITAIRES
// ============================================

/**
 * Géolocalise une adresse IP via l'API ip-api.com
 * 
 * Cette fonction interroge l'API gratuite ip-api.com pour obtenir la position géographique
 * approximative d'une adresse IP publique.
 * 
 * API utilisée : http://ip-api.com/json/{ip}
 * Format de réponse : JSON avec champs status, city, regionName, lat, lon
 * Limite gratuite : 45 requêtes/minute
 * 
 * @param string $ip Adresse IP publique à géolocaliser
 * @return array Tableau associatif avec latitude, longitude, city, region, zip, country
 *               ou tableau avec clé 'error' en cas d'échec
 */
function geolocateIP($ip)
{
    try {
        // Format JSON pour faciliter le parsing
        $api_url = "http://ip-api.com/json/{$ip}?lang=fr&fields=status,message,country,regionName,city,zip,lat,lon";

        // Configuration avec timeout
        $options = [
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);

        $json_string = @file_get_contents($api_url, false, $context);

        if ($json_string === false) {
            $error = error_get_last();
            return ['error' => 'Impossible de contacter l\'API : ' . ($error['message'] ?? 'Erreur inconnue')];
        }

        $data = json_decode($json_string, true);

        if ($data === null) {
            return ['error' => 'Réponse JSON invalide : ' . substr($json_string, 0, 100)];
        }

        if ($data['status'] !== 'success') {
            return ['error' => $data['message'] ?? 'Géolocalisation échouée'];
        }

        return [
            'latitude' => $data['lat'],
            'longitude' => $data['lon'],
            'city' => $data['city'],
            'region' => $data['regionName'],
            'zip' => $data['zip'] ?? '',
            'country' => $data['country']
        ];

    } catch (Exception $e) {
        return ['error' => 'Exception : ' . $e->getMessage()];
    }
}

/**
 * Récupère les données de qualité de l'air depuis l'API ATMO Grand Est
 * 
 * Cette fonction interroge le service ArcGIS d'ATMO Grand Est pour obtenir l'indice
 * de qualité de l'air et les niveaux de pollution par polluant (NO2, O3, PM10, PM2.5).
 * Les données sont mises à jour quotidiennement par ATMO Grand Est.
 * 
 * L'API retourne des données historiques et des prévisions. La fonction privilégie
 * les données actuelles ou passées, et n'utilise les prévisions que si aucune donnée
 * du jour n'est disponible.
 * 
 * API utilisée : https://services3.arcgis.com/Is0UwT37raQYl9Jj/arcgis/rest/services/ind_grandest/FeatureServer/0/query
 * Source : ATMO Grand Est (www.atmo-grandest.eu)
 * 
 * @param string $city_name Nom de la ville (par défaut 'Nancy')
 * @return array Tableau avec zone, code_quality (1-6), quality_label, color, date,
 *               is_forecast (bool), pollutants (NO2, O3, PM10, PM2.5), source
 *               ou tableau avec clé 'error' en cas d'échec
 */
function getAirQuality($city_name = 'Nancy')
{
    try {
        $encoded_city = urlencode($city_name);
        $api_url = "https://services3.arcgis.com/Is0UwT37raQYl9Jj/arcgis/rest/services/ind_grandest/FeatureServer/0/query?where=lib_zone%3D%27{$encoded_city}%27&outFields=*&f=pjson";

        $context_options = [
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($context_options);

        $json_string = @file_get_contents($api_url, false, $context);

        if ($json_string === false) {
            return ['error' => 'Impossible de récupérer les données de qualité de l\'air'];
        }

        $data = json_decode($json_string, true);

        if ($data === null || !isset($data['features'])) {
            return ['error' => 'Réponse JSON invalide'];
        }

        if (empty($data['features'])) {
            return ['error' => "Aucune donnée disponible pour {$city_name}"];
        }

        $current_time = time();
        $best_match = null;
        $closest_time_diff = PHP_INT_MAX;
        $is_forecast = false;

        foreach ($data['features'] as $feature) {
            $attrs = $feature['attributes'];
            $date_ech = intval($attrs['date_ech'] ?? 0) / 1000;

            if ($date_ech <= $current_time) {
                $time_diff = $current_time - $date_ech;

                if ($time_diff < $closest_time_diff) {
                    $closest_time_diff = $time_diff;
                    $best_match = $attrs;
                }
            }
        }

        if (!$best_match) {
            $is_forecast = true;
            $closest_time_diff = PHP_INT_MAX;

            foreach ($data['features'] as $feature) {
                $attrs = $feature['attributes'];
                $date_ech = intval($attrs['date_ech'] ?? 0) / 1000;

                if ($date_ech > $current_time) {
                    $time_diff = $date_ech - $current_time;

                    if ($time_diff < $closest_time_diff) {
                        $closest_time_diff = $time_diff;
                        $best_match = $attrs;
                    }
                }
            }
        }

        if (!$best_match) {
            return ['error' => 'Aucune donnée valide trouvée'];
        }

        $quality_labels = [
            1 => 'Bon',
            2 => 'Moyen',
            3 => 'Dégradé',
            4 => 'Mauvais',
            5 => 'Très mauvais',
            6 => 'Extrêmement mauvais'
        ];

        $pollutant_labels = [
            1 => 'Bon',
            2 => 'Moyen',
            3 => 'Dégradé',
            4 => 'Mauvais',
            5 => 'Très mauvais'
        ];

        return [
            'zone' => $best_match['lib_zone'] ?? 'Grand Est',
            'code_quality' => intval($best_match['code_qual'] ?? 1),
            'quality_label' => $best_match['lib_qual'] ?? $quality_labels[intval($best_match['code_qual'] ?? 1)],
            'color' => $best_match['coul_qual'] ?? '#50F0E6',
            'date' => date('d/m/Y', intval($best_match['date_ech'] ?? 0) / 1000),
            'is_forecast' => $is_forecast,
            'pollutants' => [
                'NO2' => [
                    'code' => intval($best_match['code_no2'] ?? 1),
                    'label' => $pollutant_labels[intval($best_match['code_no2'] ?? 1)]
                ],
                'O3' => [
                    'code' => intval($best_match['code_o3'] ?? 1),
                    'label' => $pollutant_labels[intval($best_match['code_o3'] ?? 1)]
                ],
                'PM10' => [
                    'code' => intval($best_match['code_pm10'] ?? 1),
                    'label' => $pollutant_labels[intval($best_match['code_pm10'] ?? 1)]
                ],
                'PM2.5' => [
                    'code' => intval($best_match['code_pm25'] ?? 1),
                    'label' => $pollutant_labels[intval($best_match['code_pm25'] ?? 1)]
                ]
            ],
            'source' => $best_match['source'] ?? 'ATMO Grand Est'
        ];

    } catch (Exception $e) {
        return ['error' => 'Exception : ' . $e->getMessage()];
    }
}

/**
 * Récupère les données de surveillance du SARS-CoV-2 dans les eaux usées
 * La fonction extrait les 10 dernières semaines de données pour la station de Maxeville
 * (qui couvre le Grand Nancy) et calcule la tendance épidémique (hausse/baisse/stable).
 * 
 * API utilisée : https://www.data.gouv.fr/fr/datasets/r/2963ccb5-344d-4978-bdd3-08aaf9efe514
 * Format : CSV avec séparateur point-virgule
 * Source : OBEPINE - Réseau OBEPINE/Sorbonne Université
 * Station : MAXEVILLE (Grand Nancy)
 * 
 * @return array Tableau avec weeks (semaines), values (concentrations), latest_week,
 *               latest_value, trend (hausse/baisse/stable), unit
 *               ou tableau avec clé 'error' en cas d'échec
 */
function getCovidData()
{
    try {
        $csv_url = "https://www.data.gouv.fr/fr/datasets/r/2963ccb5-344d-4978-bdd3-08aaf9efe514";

        $context_options = [
            'http' => [
                'timeout' => 15,
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($context_options);

        $csv_content = @file_get_contents($csv_url, false, $context);

        if ($csv_content === false) {
            return ['error' => 'Impossible de récupérer les données OBEPINE'];
        }

        $lines = explode("\n", $csv_content);
        if (count($lines) < 2) {
            return ['error' => 'Format CSV invalide'];
        }

        $header = str_getcsv($lines[0], ';');

        $maxeville_index = array_search('MAXEVILLE', $header);
        if ($maxeville_index === false) {
            return ['error' => 'Station MAXEVILLE non trouvée dans les données'];
        }

        $weeks = [];
        $values = [];
        $count = 0;
        $max_weeks = 10;

        for ($i = count($lines) - 1; $i >= 1 && $count < $max_weeks; $i--) {
            $row = str_getcsv($lines[$i], ';');
            if (count($row) <= $maxeville_index)
                continue;

            $week = $row[0] ?? '';
            $value = $row[$maxeville_index] ?? 'NA';

            $value = str_replace(',', '.', $value);

            if ($value !== 'NA' && is_numeric($value) && !empty($week)) {
                array_unshift($weeks, $week);
                array_unshift($values, floatval($value));
                $count++;
            }
        }

        if (empty($weeks)) {
            return ['error' => 'Aucune donnée disponible pour Maxeville'];
        }

        // Calculer la tendance (dernière valeur vs moyenne des 3 précédentes)
        $latest = end($values);
        $trend = 'stable';
        if (count($values) >= 4) {
            $previous_avg = (($values[count($values) - 2] + $values[count($values) - 3] + $values[count($values) - 4]) / 3);
            if ($latest > $previous_avg * 1.1) {
                $trend = 'hausse';
            } elseif ($latest < $previous_avg * 0.9) {
                $trend = 'baisse';
            }
        }

        return [
            'weeks' => $weeks,
            'values' => $values,
            'latest_week' => end($weeks),
            'latest_value' => $latest,
            'trend' => $trend,
            'unit' => 'copies génomes/L'
        ];

    } catch (Exception $e) {
        return ['error' => 'Exception : ' . $e->getMessage()];
    }
}

/**
 * Récupère les incidents de circulation en temps réel via l'API TomTom Traffic
 * 
 * Cette fonction interroge l'API TomTom Traffic Incidents pour obtenir tous les
 * incidents de circulation (accidents, travaux, embouteillages, etc.) dans une zone
 * définie autour des coordonnées fournies.
 * 
 * API utilisée : https://api.tomtom.com/traffic/services/5/incidentDetails
 * Documentation : https://developer.tomtom.com/traffic-api/documentation
 * 
 * @param float $latitude Latitude du point central (ex: 48.6937 pour Nancy)
 * @param float $longitude Longitude du point central (ex: 6.1834 pour Nancy)
 * @param string $api_key Clé d'authentification TomTom
 * @return array Tableau avec 'incidents' (array de détails) et 'count' (nombre total)
 *               ou tableau avec clé 'error' en cas d'échec
 */
function getTrafficIncidents($latitude, $longitude, $api_key)
{
    try {
        // Définir une bounding box autour de Nancy (environ 10km de rayon)
        $lat_delta = 0.1; // ~11km
        $lon_delta = 0.15; // ~11km

        $bbox = sprintf(
            "%s,%s,%s,%s",
            $longitude - $lon_delta, // min lon
            $latitude - $lat_delta,  // min lat
            $longitude + $lon_delta, // max lon
            $latitude + $lat_delta   // max lat
        );

        $api_url = "https://api.tomtom.com/traffic/services/5/incidentDetails?key={$api_key}&bbox={$bbox}&fields={incidents{type,geometry{type,coordinates},properties{iconCategory,magnitudeOfDelay,events{description,code,iconCategory},startTime,endTime,from,to,length,delay,roadNumbers,timeValidity}}}&language=fr-FR&categoryFilter=0,1,2,3,4,5,6,7,8,9,10,11,14&timeValidityFilter=present";

        $context_options = [
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($context_options);

        $json_string = @file_get_contents($api_url, false, $context);

        if ($json_string === false) {
            return ['error' => 'Impossible de contacter l\'API TomTom'];
        }

        $data = json_decode($json_string, true);

        if ($data === null) {
            return ['error' => 'Réponse JSON invalide'];
        }

        $incidents = [];
        if (isset($data['incidents'])) {
            foreach ($data['incidents'] as $incident) {
                $coords = $incident['geometry']['coordinates'] ?? null;
                $props = $incident['properties'] ?? [];

                if ($coords) {
                    // TomTom renvoie [lon, lat] ou [[lon, lat], ...]
                    if (is_array($coords[0])) {
                        // Prendre le premier point
                        $lon = $coords[0][0];
                        $lat = $coords[0][1];
                    } else {
                        $lon = $coords[0];
                        $lat = $coords[1];
                    }

                    $type_map = [
                        0 => 'Incident inconnu',
                        1 => 'Accident',
                        2 => 'Brouillard',
                        3 => 'Conditions dangereuses',
                        4 => 'Pluie',
                        5 => 'Glace',
                        6 => 'Embouteillage',
                        7 => 'Route fermée',
                        8 => 'Travaux',
                        9 => 'Vent',
                        10 => 'Inondation',
                        11 => 'Détour',
                        14 => 'Route glissante'
                    ];

                    $icon_category = $props['iconCategory'] ?? 0;
                    $description = '';

                    if (isset($props['events'][0]['description'])) {
                        $description = $props['events'][0]['description'];
                    }

                    $incidents[] = [
                        'lat' => $lat,
                        'lon' => $lon,
                        'type' => $type_map[$icon_category] ?? 'Incident',
                        'description' => $description,
                        'from' => $props['from'] ?? '',
                        'to' => $props['to'] ?? '',
                        'start_time' => isset($props['startTime']) ? date('d/m/Y H:i', strtotime($props['startTime'])) : '',
                        'end_time' => isset($props['endTime']) ? date('d/m/Y H:i', strtotime($props['endTime'])) : '',
                        'delay' => $props['delay'] ?? 0,
                        'severity' => $props['magnitudeOfDelay'] ?? 0
                    ];
                }
            }
        }

        return ['incidents' => $incidents, 'count' => count($incidents)];

    } catch (Exception $e) {
        return ['error' => 'Exception : ' . $e->getMessage()];
    }
}

/**
 * Géocode une adresse textuelle en coordonnées GPS via l'API Nominatim
 * 
 * Cette fonction utilise le service de géocodage Nominatim d'OpenStreetMap pour
 * convertir une adresse textuelle (ex: "IUT Charlemagne Nancy France") en
 * coordonnées latitude/longitude exploitables.
 * 
 * Utilisée uniquement comme fallback lorsque l'utilisateur n'est pas à Nancy,
 * pour récupérer les coordonnées de l'IUT Nancy-Charlemagne et afficher les
 * données locales pertinentes.
 * 
 * API utilisée : https://nominatim.openstreetmap.org/search
 * Note : Requiert un User-Agent personnalisé (règle Nominatim)
 * 
 * @param string $address Adresse textuelle à géocoder
 * @return array Tableau avec latitude, longitude, display_name
 *               ou tableau avec clé 'error' en cas d'échec
 */
function geocodeAddress($address)
{
    try {
        $encoded_address = urlencode($address);
        $api_url = "https://nominatim.openstreetmap.org/search?q={$encoded_address}&format=json&limit=1";

        // Nominatim requiert un User-Agent
        $options = [
            'http' => [
                'header' => "User-Agent: AtmosphereApp/1.0\r\n"
            ]
        ];
        $context = stream_context_create($options);

        $json_string = @file_get_contents($api_url, false, $context);

        if ($json_string === false) {
            return ['error' => 'Impossible de géocoder l\'adresse'];
        }

        $data = json_decode($json_string, true);

        if (empty($data)) {
            return ['error' => 'Adresse non trouvée'];
        }

        return [
            'latitude' => floatval($data[0]['lat']),
            'longitude' => floatval($data[0]['lon']),
            'display_name' => $data[0]['display_name']
        ];

    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Récupération de l'adresse IP du client depuis les variables serveur
 */
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// En production, pas de simulation d'IP - utilisation de l'IP réelle du client

// Appel de l'API ip-api.com pour géolocaliser l'adresse IP
$geoloc = geolocateIP($client_ip);

if (isset($geoloc['error'])) {
    // En cas d'échec de géolocalisation, utiliser les coordonnées de Nancy par défaut
    $latitude = 48.6937;
    $longitude = 6.1834;
    $location_name = "Nancy";
} else {
    $latitude = $geoloc['latitude'];
    $longitude = $geoloc['longitude'];
    $location_name = $geoloc['city'] . ", " . $geoloc['region'];

    // Si l'utilisateur n'est pas à Nancy, on récupère les coordonnées de l'IUT
    $is_nancy = (stripos($geoloc['city'], 'Nancy') !== false);

    if (!$is_nancy) {

        // Appel de Nominatim OSM pour géocoder l'adresse de l'IUT
        $iut = geocodeAddress("IUT Charlemagne Nancy France");

        if (isset($iut['error'])) {
            // Coordonnées GPS exactes de l'IUT Nancy-Charlemagne (2 Ter Boulevard Charlemagne)
            $latitude = 48.68944;
            $longitude = 6.17611;
            $location_name = "IUT Nancy-Charlemagne";
        } else {
            $latitude = $iut['latitude'];
            $longitude = $iut['longitude'];
            $location_name = "IUT Nancy-Charlemagne";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atmosphere - Aide à la décision de déplacement</title>
    <link rel="stylesheet" href="styles.css">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <header>
        <h1>🌍 Atmosphere</h1>
        <p>Aide à la décision : faut-il prendre sa voiture aujourd'hui ?</p>
    </header>

    <main>
        <!-- PARTIE 1 : MÉTÉO -->
        <section id="meteo">
            <h2>☀️ Météo du jour</h2>
            <div id="meteo-content">
                <?php
                /**
                 * Fonction pour récupérer et transformer les données météo via XSL
                 * 
                 * Cette fonction interroge l'API InfoClimat pour obtenir les prévisions météo
                 * au format XML, puis applique une transformation XSL pour générer
                 * le HTML final avec les 3 périodes de la journée (Matin, Midi, Soir).
                 * 
                 * API utilisée : InfoClimat API (infoclimat.fr/public-api)
                 * Format : XML avec balises <previsions> et <echeance>
                 * Transformation : meteo.xsl (filtre sur heures 6h, 12h, 18h)
                 * 
                 * @param float $latitude Latitude du point de prévision
                 * @param float $longitude Longitude du point de prévision
                 * @return string HTML généré par la transformation XSL ou message d'erreur
                 */
                function getMeteoHTML($latitude, $longitude)
                {
                    // Clé API InfoClimat fournie dans le sujet du TP
                    $api_key = "ARsDFFIsBCZRfFtsD3lSe1Q8ADUPeVRzBHgFZgtuAH1UMQNgUTNcPlU5VClSfVZkUn8AYVxmVW0Eb1I2WylSLgFgA25SNwRuUT1bPw83UnlUeAB9DzFUcwR4BWMLYwBhVCkDb1EzXCBVOFQoUmNWZlJnAH9cfFVsBGRSPVs1UjEBZwNkUjIEYVE6WyYPIFJjVGUAZg9mVD4EbwVhCzMAMFQzA2JRMlw5VThUKFJiVmtSZQBpXGtVbwRlUjVbKVIuARsDFFIsBCZRfFtsD3lSe1QyAD4PZA%3D%3D";
                    $api_url = "https://www.infoclimat.fr/public-api/gfs/xml?_ll={$latitude},{$longitude}&_auth={$api_key}&_c=19f3aa7d766b6ba91191c8be71dd1ab2";

                    try {
                        $context_options = array(
                            'http' => array(
                                'timeout' => 10,
                                'ignore_errors' => true
                            )
                        );
                        $context = stream_context_create($context_options);

                        $xml_string = @file_get_contents($api_url, false, $context);

                        if ($xml_string === false) {
                            $error = error_get_last();
                            return "<div class='error'>
                                <p>⚠️ Impossible de récupérer les données météo</p>
                                <p><small>Erreur : " . htmlspecialchars($error['message'] ?? 'Connexion à l\'API échouée') . "</small></p>
                            </div>";
                        }

                        if (strpos($xml_string, '<?xml') === false) {
                            return "<div class='error'>
                                <p>⚠️ La réponse de l'API n'est pas du XML valide</p>
                                <p><small>Réponse reçue : " . htmlspecialchars(substr($xml_string, 0, 200)) . "...</small></p>
                            </div>";
                        }

                        $xml = new DOMDocument();
                        if (!$xml->loadXML($xml_string)) {
                            return "<p class='error'>⚠️ Erreur lors du parsing XML</p>";
                        }

                        // Création d'une version du XML avec déclaration DOCTYPE pour validation
                        $xmlWithDTD = "<?xml version='1.0' encoding='UTF-8'?>\n";
                        $xmlWithDTD .= "<!DOCTYPE previsions SYSTEM 'meteo.dtd'>\n";
                        $xmlContent = preg_replace('/<\?xml.*?\?>\s*/', '', $xml_string);
                        $xmlWithDTD .= $xmlContent;
                        
                        $validatedXml = new DOMDocument();
                        libxml_use_internal_errors(true);
                        $validatedXml->loadXML($xmlWithDTD);
                        
                        if (@$validatedXml->validate()) {
                            echo "<!-- ✓ XML validé avec succès contre meteo.dtd -->\n";
                        } else {
                            echo "<!-- ⚠ Avertissement : XML non conforme au DTD (transformation continue) -->\n";
                            $errors = libxml_get_errors();
                            foreach ($errors as $error) {
                                echo "<!-- Erreur DTD ligne {$error->line}: " . trim($error->message) . " -->\n";
                            }
                            libxml_clear_errors();
                        }
                        libxml_use_internal_errors(false);

                        $xsl = new DOMDocument();
                        $xsl->load('meteo.xsl');

                        $processor = new XSLTProcessor();
                        $processor->importStylesheet($xsl);

                        $result = $processor->transformToXML($xml);

                        if (empty($result)) {
                            return "<p class='error'>⚠️ La transformation XSL n'a produit aucun résultat</p>";
                        }

                        return $result;

                    } catch (Exception $e) {
                        return "<p class='error'>⚠️ Erreur lors du traitement météo : " . htmlspecialchars($e->getMessage()) . "</p>";
                    }
                }

                // Appel de la fonction et affichage du HTML généré par transformation XSL
                echo getMeteoHTML($latitude, $longitude);
                ?>
            </div>
        </section>

        <!-- PARTIE 2 : CARTE TRAFIC -->
        <section id="trafic">
            <h2>🚗 Difficultés de circulation</h2>
            <?php
            // Appel de l'API TomTom Traffic pour récupérer les incidents en temps réel
            $tomtom_key = "S4x8UoNawwEpYkW4u3Hzmz4yyjJZPcrI";
            $traffic = getTrafficIncidents($latitude, $longitude, $tomtom_key);

            if (isset($traffic['error'])) {
                $traffic_json = json_encode([]);
            } else {
                $traffic_json = json_encode($traffic['incidents']);
            }
            ?>
            <div id="map" style="height: 500px;"></div>
        </section>

        <!-- PARTIE 3 : COVID/SRAS -->
        <section id="covid">
            <h2>🦠 État épidémique (SRAS)</h2>
            <?php
            // Appel de l'API data.gouv.fr pour récupérer les données OBEPINE
            $covid = getCovidData();

            if (isset($covid['error'])) {
                $covid_json = json_encode(['weeks' => [], 'values' => []]);
            } else {
                $covid_json = json_encode(['weeks' => $covid['weeks'], 'values' => $covid['values']]);
            }
            ?>
            <div style="max-width: 800px; margin: 20px auto;">
                <canvas id="covidChart" style="max-height: 300px;"></canvas>
            </div>
        </section>

        <!-- PARTIE 4 : QUALITÉ DE L'AIR -->
        <section id="air-quality">
            <h2>💨 Qualité de l'air</h2>
            <?php
            $city_for_air = 'Nancy';
            if (stripos($location_name, 'Nancy') !== false) {
                $city_for_air = 'Nancy';
            }

            $air = getAirQuality($city_for_air);

            if (isset($air['error'])) {
            } else {
                // Affichage de l'indice global de qualité de l'air avec couleur dynamique
                echo "<div class='air-quality-index' style='background: {$air['color']};'>\n";
                echo "    <h3>Indice de qualité : {$air['quality_label']}</h3>\n";
                echo "    <div class='air-index-value'>{$air['code_quality']}/6</div>\n";
                $date_label = $air['is_forecast'] ? "📍 {$air['zone']} • Prévision du {$air['date']}" : "📍 {$air['zone']} • {$air['date']}";
                echo "    <p class='air-location'>{$date_label}</p>\n";
                echo "</div>\n";

                // Affichage détaillé des 4 polluants surveillés avec code couleur
                echo "<div class='pollutants-grid'>\n";
                foreach ($air['pollutants'] as $name => $pollutant) {
                    $pollutant_color = '#27ae60';
                    if ($pollutant['code'] >= 4)
                        $pollutant_color = '#e74c3c';
                    elseif ($pollutant['code'] >= 3)
                        $pollutant_color = '#f39c12';
                    elseif ($pollutant['code'] >= 2)
                        $pollutant_color = '#3498db';

                    echo "    <div class='pollutant-card' style='border-left-color: {$pollutant_color};'>\n";
                    echo "        <h4 style='color: {$pollutant_color};'>{$name}</h4>\n";
                    echo "        <p>{$pollutant['label']}</p>\n";
                    echo "    </div>\n";
                }
                echo "</div>\n";

                // Recommandations adaptées selon la qualité de l'air
                echo "<div class='air-recommendations'>\n";
                echo "    <h4>💡 Recommandations</h4>\n";
                echo "    <ul>\n";

                if ($air['code_quality'] >= 4) {
                    echo "        <li>⛔ Évitez les déplacements en voiture si possible</li>\n";
                    echo "        <li>🎽 Limitez les activités physiques intenses en extérieur</li>\n";
                    echo "        <li>😷 Portez un masque pour les personnes sensibles</li>\n";
                } elseif ($air['code_quality'] >= 3) {
                    echo "        <li>⚠️ Privilégiez les transports en commun ou le covoiturage</li>\n";
                    echo "        <li>🚶 Préférez la marche ou le vélo pour les courtes distances</li>\n";
                } else {
                    echo "        <li>✅ Conditions favorables pour se déplacer</li>\n";
                    echo "        <li>🌳 Bonne qualité de l'air, profitez-en !</li>\n";
                }

                echo "    </ul>\n";
                echo "</div>\n";
            }
            ?>
        </section>

        <!-- RESSOURCES UTILISÉES -->
        <section id="resources">
            <h2>🔗 APIs et ressources utilisées</h2>
            <ul>
                <li><strong>Géolocalisation IP :</strong> <a href="http://ip-api.com/"
                        target="_blank">http://ip-api.com/</a></li>
                <li><strong>Météo :</strong> <a href="https://www.infoclimat.fr/public-api/" target="_blank">InfoClimat
                        API</a></li>
                <li><strong>Géocodage :</strong> <a href="https://nominatim.openstreetmap.org/"
                        target="_blank">Nominatim (OpenStreetMap)</a></li>
                <li><strong>Trafic :</strong> <a href="https://developer.tomtom.com/traffic-api" target="_blank">TomTom
                        Traffic API</a></li>
                <li><strong>Carte :</strong> <a href="https://leafletjs.com/" target="_blank">Leaflet +
                        OpenStreetMap</a></li>
                <li><strong>SRAS/COVID :</strong> <a
                        href="https://www.data.gouv.fr/fr/datasets/surveillance-du-sars-cov-2-dans-les-eaux-usees-obepine/"
                        target="_blank">OBEPINE (data.gouv.fr)</a></li>
                <li><strong>Qualité de l'air :</strong> <a href="https://www.atmo-grandest.eu/" target="_blank">ATMO
                        Grand Est</a></li>
                <li><strong>Dépôt Git :</strong> <a href="#" target="_blank">Lien GitHub (à compléter)</a></li>
            </ul>
        </section>
    </main>

    <footer>
        <p>Projet Interopérabilité - BUT3 - <?php echo date('Y'); ?></p>
    </footer>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <script>
        // Coordonnées depuis PHP
        const centerLat = <?php echo $latitude; ?>;
        const centerLon = <?php echo $longitude; ?>;
        const trafficIncidents = <?php echo $traffic_json; ?>;

        const map = L.map('map').setView([centerLat, centerLon], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        L.marker([centerLat, centerLon], {
            icon: L.icon({
                iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-blue.png',
                shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
                iconSize: [25, 41],
                iconAnchor: [12, 41],
                popupAnchor: [1, -34],
                shadowSize: [41, 41]
            })
        })
            .bindPopup('<b>📍 Votre position</b>')
            .addTo(map);

        if (trafficIncidents && trafficIncidents.length > 0) {
            trafficIncidents.forEach((incident, index) => {
                // Détermination de la couleur du marqueur selon la sévérité
                let iconColor = 'orange';
                if (incident.severity >= 3) iconColor = 'red';
                else if (incident.severity <= 1) iconColor = 'yellow';

                const marker = L.marker([incident.lat, incident.lon], {
                    icon: L.icon({
                        iconUrl: `https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-${iconColor}.png`,
                        shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
                        iconSize: [25, 41],
                        iconAnchor: [12, 41],
                        popupAnchor: [1, -34],
                        shadowSize: [41, 41]
                    })
                });

                let popupContent = `<div style="min-width: 200px;">
                    <h3 style="margin: 0 0 10px 0; color: #e74c3c;">🚨 ${incident.type}</h3>`;

                if (incident.description) {
                    popupContent += `<p><strong>Description :</strong><br>${incident.description}</p>`;
                }

                if (incident.from || incident.to) {
                    popupContent += `<p><strong>Localisation :</strong><br>`;
                    if (incident.from) popupContent += `De : ${incident.from}<br>`;
                    if (incident.to) popupContent += `À : ${incident.to}`;
                    popupContent += `</p>`;
                }

                if (incident.start_time || incident.end_time) {
                    popupContent += `<p><strong>Période :</strong><br>`;
                    if (incident.start_time) popupContent += `📅 Début : ${incident.start_time}<br>`;
                    if (incident.end_time) popupContent += `📅 Fin : ${incident.end_time}`;
                    popupContent += `</p>`;
                }

                if (incident.delay > 0) {
                    const delayMin = Math.round(incident.delay / 60);
                    popupContent += `<p><strong>⏱️ Retard estimé :</strong> ${delayMin} min</p>`;
                }

                popupContent += `</div>`;

                marker.bindPopup(popupContent);
                marker.addTo(map);
            });
        }

        const covidData = <?php echo $covid_json; ?>;

        if (covidData.weeks && covidData.weeks.length > 0) {
            const ctx = document.getElementById('covidChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: covidData.weeks,
                    datasets: [{
                        label: 'Concentration SARS-CoV-2 (copies génomes/L)',
                        data: covidData.values,
                        borderColor: 'rgb(231, 76, 60)',
                        backgroundColor: 'rgba(231, 76, 60, 0.1)',
                        tension: 0.3,
                        fill: true,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        title: {
                            display: true,
                            text: 'Évolution du virus SARS-CoV-2 dans les eaux usées - Maxeville',
                            font: {
                                size: 16
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Copies génomes/L'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Semaine'
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>

</html>