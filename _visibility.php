<?php
/**
 * _visibility.php — Helper compartido de visibilidad por franjas horarias.
 *
 * Lo usan index.php, carta/index.php y tienda.php para decidir si un producto
 * debe mostrarse en el día/hora actual, contemplando cruces de medianoche
 * (ej. Viernes de 20:30 a 00:30 sigue visible el Sábado a las 00:15).
 */

if (!function_exists('prevDayEs')) {
function prevDayEs(string $dayEs): string {
    $days = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    $idx  = array_search($dayEs, $days, true);
    if ($idx === false) return $dayEs;
    return $days[($idx + 6) % 7];
}}

if (!function_exists('isItemVisibleNow')) {
function isItemVisibleNow(array $item, string $currentDayEs, string $currentTime): bool {
    $json = $item['visible_days'] ?? null;
    $yesterdayEs = prevDayEs($currentDayEs);

    // Sin restricción de días → siempre visible (revisa horario legado si existe)
    if (!$json || $json === '[]' || $json === 'null') {
        $start = $item['visible_start_time'] ?? null;
        $end   = $item['visible_end_time']   ?? null;
        if (!$start && !$end) return true;
        if ($start && $end) {
            if ($start <= $end) return $currentTime >= $start && $currentTime <= $end;
            return $currentTime >= $start || $currentTime <= $end;
        }
        return true;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded) || empty($decoded)) return true;

    // Formato nuevo: array de objetos [{'days':['Viernes'],'start':'20:30','end':'00:30'}, ...]
    if (isset($decoded[0]) && is_array($decoded[0]) && array_key_exists('start', $decoded[0])) {
        foreach ($decoded as $franja) {
            $days  = isset($franja['days']) && is_array($franja['days']) ? $franja['days'] : [];
            $start = trim((string)($franja['start'] ?? ''));
            $end   = trim((string)($franja['end']   ?? ''));
            if (empty($days)) continue;

            // Normalizar HH:MM -> HH:MM:00 para comparar con $currentTime (HH:MM:SS)
            $sCmp = (strlen($start) === 5) ? ($start . ':00') : $start;
            $eCmp = (strlen($end)   === 5) ? ($end   . ':59') : $end;

            if ($start === '' || $end === '') {
                if (in_array($currentDayEs, $days, true)) return true;
                continue;
            }

            if ($start <= $end) {
                if (in_array($currentDayEs, $days, true) && $currentTime >= $sCmp && $currentTime <= $eCmp) {
                    return true;
                }
            } else {
                // Cruza medianoche (ej. Viernes 20:30 a 00:30):
                // Parte nocturna del mismo día (20:30..23:59:59) O madrugada del día siguiente (00:00..00:30)
                if (in_array($currentDayEs, $days, true) && $currentTime >= $sCmp) {
                    return true;
                }
                if (in_array($yesterdayEs, $days, true) && $currentTime <= $eCmp) {
                    return true;
                }
            }
        }
        return false;
    }

    // Formato legado: array plano de días
    $start = trim((string)($item['visible_start_time'] ?? ''));
    $end   = trim((string)($item['visible_end_time']   ?? ''));
    if ($start === '' || $end === '') {
        return in_array($currentDayEs, $decoded, true);
    }
    if ($start <= $end) {
        return in_array($currentDayEs, $decoded, true) && $currentTime >= $start && $currentTime <= $end;
    }
    return (in_array($currentDayEs, $decoded, true) && $currentTime >= $start)
        || (in_array($yesterdayEs,  $decoded, true) && $currentTime <= $end);
}}
