<?php
// reviews_proxy.php (v3)
// Devuelve JSON con rating + reseñas desde Google Places, cacheado 1h.

@header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE);

$API_KEY  = 'AIzaSyCjMUhC4_LUGn-HzuoE0-p9XXhgihJ65BU';
$PLACE_ID = 'ChIJL1mOXwKZMpQRnHgmkrj6BKI';
$LANG     = 'es';

if (!$API_KEY || !$PLACE_ID) {
  http_response_code(500);
  echo json_encode(['error'=>'Falta API_KEY o PLACE_ID']); exit;
}

$cacheFile = __DIR__ . '/cache_google_reviews.json';
$ttl = 3600; // 1 hora

// Servir desde caché si es válido
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
  readfile($cacheFile); exit;
}

function http_get($url) {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 15,
      CURLOPT_TIMEOUT        => 30,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_USERAGENT      => 'ArrabbiataBot/1.0',
    ]);
    $out  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($out === false) return ['body'=>false, 'error'=>$err, 'http'=>0];
    return ['body'=>$out, 'error'=>'', 'http'=>$code];
  }
  // Fallback sin curl
  $ctx = stream_context_create([
    'http' => ['timeout'=>30, 'header'=>"User-Agent: ArrabbiataBot/1.0\r\n"],
    'ssl'  => ['verify_peer'=>true]
  ]);
  $out = @file_get_contents($url, false, $ctx);
  return ['body'=>$out, 'error'=>$out===false?'file_get_contents falló':'', 'http'=>0];
}

$fields = 'name,rating,user_ratings_total,reviews,url';
$params = http_build_query([
  'place_id'                => $PLACE_ID,
  'fields'                  => $fields,
  'language'                => $LANG,
  'reviews_sort'            => 'newest',
  'reviews_no_translations' => 'false',
  'key'                     => $API_KEY
]);
$url = 'https://maps.googleapis.com/maps/api/place/details/json?' . $params;

$result = http_get($url);

if ($result['body'] === false) {
  http_response_code(502);
  echo json_encode([
    'error'  => 'No se pudo contactar Google. Verificá que el servidor tiene salida a internet (curl/allow_url_fopen).',
    'detail' => $result['error']
  ]); exit;
}

$data = json_decode($result['body'], true);
if (!is_array($data)) {
  http_response_code(502);
  echo json_encode(['error'=>'Respuesta no es JSON válido', 'raw'=>substr($result['body'],0,300)]); exit;
}

$status = $data['status'] ?? 'UNKNOWN';
if ($status !== 'OK') {
  // Mensajes de error descriptivos según el código de Google
  $msgs = [
    'REQUEST_DENIED'      => 'API key inválida, sin permisos para Places API, o la key tiene restricciones de dominio/IP que bloquean este servidor. Revisá la Google Cloud Console.',
    'OVER_QUERY_LIMIT'    => 'Límite de consultas excedido. Revisá la cuota en Google Cloud Console.',
    'INVALID_REQUEST'     => 'Place ID inválido o falta un parámetro requerido.',
    'NOT_FOUND'           => 'El Place ID no existe o fue dado de baja.',
    'OVER_DAILY_LIMIT'    => 'Límite diario excedido o billing no habilitado en Google Cloud.',
    'ZERO_RESULTS'        => 'No se encontraron resultados para ese Place ID.',
  ];
  http_response_code(502);
  echo json_encode([
    'error'       => $msgs[$status] ?? "Google devolvió estado: $status",
    'status'      => $status,
    'error_message' => $data['error_message'] ?? ''
  ]); exit;
}

$place   = $data['result'] ?? [];
$reviews = $place['reviews'] ?? [];

$mapped = array_map(function($r) {
  $text = '';
  if (!empty($r['text']) && trim($r['text']) !== '') {
    $text = $r['text'];
  } elseif (!empty($r['original_text']['text'])) {
    $text = $r['original_text']['text'];
  }
  return [
    'author' => $r['author_name'] ?? 'Usuario de Google',
    'photo'  => $r['profile_photo_url'] ?? '',
    'rating' => $r['rating'] ?? 0,
    'time'   => $r['relative_time_description'] ?? '',
    'text'   => $text,
    'link'   => $r['author_url'] ?? ''
  ];
}, $reviews);

$out = [
  'name'     => $place['name'] ?? '',
  'rating'   => $place['rating'] ?? 0,
  'count'    => $place['user_ratings_total'] ?? 0,
  'url'      => $place['url'] ?? ('https://www.google.com/maps/place/?q=place_id:' . $PLACE_ID),
  'place_id' => $PLACE_ID,
  'reviews'  => $mapped
];

// Cachear solo si la respuesta es válida
file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
