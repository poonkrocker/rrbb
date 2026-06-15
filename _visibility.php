<?php
/**
 * _visibility.php — Helper compartido de visibilidad por franjas horarias.
 *
 * Lo usan index.php, carta.php y tienda.php para decidir si un producto
 * debe mostrarse en el día/hora actual.
 *
 * Soporta:
 *   - Formato nuevo: visible_days = [{"days":["Viernes"],"start":"20:00","end":"23:59"}, ...]
 *   - Formato legado: visible_days = ["Viernes", ...] + visible_start_time/visible_end_time
 *   - Sin restricción: visible_days vacío/NULL  => siempre visible
 */

if (!function_exists('isItemVisibleNow')) {
function isItemVisibleNow(array $item, string $currentDayEs, string $currentTime): bool {
    $json = $item['visible_days'] ?? null;

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

    // Formato nuevo: array de objetos con clave 'start'
    if (isset($decoded[0]['start'])) {
        foreach ($decoded as $franja) {
            $days  = $franja['days']  ?? [];
            $start = $franja['start'] ?? '';
            $end   = $franja['end']   ?? '';
            if (!in_array($currentDayEs, $days, true)) continue;
            if ($start === '' && $end === '') return true;
            if ($start !== '' && $end !== '') {
                if ($start <= $end) {
                    if ($currentTime >= $start && $currentTime <= $end) return true;
                } else {
                    if ($currentTime >= $start || $currentTime <= $end) return true;
                }
            } else {
                return true;
            }
        }
        return false;
    }

    // Formato legado: array plano de días
    if (!in_array($currentDayEs, $decoded, true)) return false;
    $start = $item['visible_start_time'] ?? null;
    $end   = $item['visible_end_time']   ?? null;
    if (!$start && !$end) return true;
    if ($start && $end) {
        if ($start <= $end) return $currentTime >= $start && $currentTime <= $end;
        return $currentTime >= $start || $currentTime <= $end;
    }
    return true;
}}
