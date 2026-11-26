<?php
/**
 * _analytics.php — Gestor unificado de Meta Pixel, Google Tag Manager / GA4
 *                  y atribución UTM + embudo propio (carta/track.php).
 *
 * Variables opcionales antes del include:
 *   - $pdo               (PDO)    conexión a BD para leer IDs desde site_settings
 *   - $analytics_source  (string) 'home' | 'carta' | 'tienda' (default 'carta')
 *   - $track_endpoint    (string) ruta hacia track.php (default 'carta/track.php')
 *   - $analytics_skip_auto_view (bool) true para no disparar 'view' automático
 */

if (!isset($__rrbb_analytics_cfg)) {
    $__rrbb_analytics_cfg = [
        'gtm_id'        => 'GTM-5JWLLKC7',
        'meta_pixel_id' => '313442609878673',
        'ga4_id'        => '',
    ];
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $__ast = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('gtm_id','meta_pixel_id','ga4_id')");
            foreach ($__ast->fetchAll(PDO::FETCH_ASSOC) as $__arow) {
                $__k = $__arow['setting_key'] ?? '';
                $__v = trim((string)($__arow['setting_value'] ?? ''));
                if (array_key_exists($__k, $__rrbb_analytics_cfg) && ($__k === 'ga4_id' || $__v !== '')) {
                    $__rrbb_analytics_cfg[$__k] = $__v;
                }
            }
        } catch (Throwable $__ae) {
            // site_settings puede no existir aún; usa los IDs por defecto
        }
    }
}

if (!function_exists('renderAnalyticsBodyNoscript')) {
function renderAnalyticsBodyNoscript(): void {
    global $__rrbb_analytics_cfg;
    $gtmId = trim((string)($__rrbb_analytics_cfg['gtm_id'] ?? ''));
    if ($gtmId !== '' && preg_match('/^GTM-[A-Z0-9]+$/i', $gtmId)) {
        $safeGtm = htmlspecialchars($gtmId, ENT_QUOTES, 'UTF-8');
        echo "<!-- Google Tag Manager (noscript) -->\n";
        echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . $safeGtm . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
    }
}}

$__gtm_id   = trim((string)($__rrbb_analytics_cfg['gtm_id'] ?? ''));
$__pixel_id = trim((string)($__rrbb_analytics_cfg['meta_pixel_id'] ?? ''));
$__ga4_id   = trim((string)($__rrbb_analytics_cfg['ga4_id'] ?? ''));
$__a_source = $analytics_source ?? 'carta';
$__t_url    = $track_endpoint ?? 'carta/track.php';
$__skip_vw  = !empty($analytics_skip_auto_view);
$__is_staging_dir = (strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/github') === 0);
?>
<?php if ($__is_staging_dir): ?>
    <meta name="robots" content="noindex, nofollow">
<?php endif; ?>
    <script>window.dataLayer = window.dataLayer || [];</script>
<?php if ($__gtm_id !== '' && preg_match('/^GTM-[A-Z0-9]+$/i', $__gtm_id)): ?>
    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer',<?php echo json_encode($__gtm_id); ?>);</script>
    <!-- End Google Tag Manager -->
<?php endif; ?>
<?php if ($__ga4_id !== '' && preg_match('/^G-[A-Z0-9]+$/i', $__ga4_id)): ?>
    <!-- Google Analytics 4 (gtag.js directo) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo htmlspecialchars($__ga4_id, ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script>
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', <?php echo json_encode($__ga4_id); ?>);
    </script>
<?php endif; ?>
<?php if ($__pixel_id !== '' && preg_match('/^\d{8,24}$/', $__pixel_id)): ?>
    <!-- Meta Pixel Code -->
    <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', <?php echo json_encode($__pixel_id); ?>);
    fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
    src="https://www.facebook.com/tr?id=<?php echo htmlspecialchars($__pixel_id, ENT_QUOTES, 'UTF-8'); ?>&ev=PageView&noscript=1"
    /></noscript>
<?php endif; ?>
    <script>
    (function() {
        var SOURCE = <?php echo json_encode($__a_source); ?>;
        var TRACK_URL = <?php echo json_encode($__t_url); ?>;
        var SKIP_VIEW = <?php echo json_encode($__skip_vw); ?>;

        // 1. Sesión anónima de pestaña (para conectar el embudo visita -> carrito -> pedido)
        var sid = '';
        try {
            sid = sessionStorage.getItem('rrbb_sid') || '';
            if (!sid) {
                sid = 's_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
                sessionStorage.setItem('rrbb_sid', sid);
            }
        } catch (e) {
            sid = 's_' + Math.random().toString(36).slice(2, 12);
        }

        // 2. Captura y persistencia de UTMs / Click IDs / Referrer inicial
        var attr = {};
        try {
            attr = JSON.parse(sessionStorage.getItem('rrbb_attr') || '{}') || {};
        } catch (e) { attr = {}; }

        try {
            var sp = new URLSearchParams(window.location.search || '');
            ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'].forEach(function(k) {
                var val = sp.get(k);
                if (val) attr[k] = val;
            });
            if (!attr.initial_ref && document.referrer && document.referrer.indexOf(location.hostname) === -1) {
                attr.initial_ref = document.referrer;
            }
            sessionStorage.setItem('rrbb_attr', JSON.stringify(attr));
        } catch (e) {}

        window.rrbbGetAttribution = function() { return attr; };
        window.rrbbGetSessionId   = function() { return sid; };

        // 3. Envío de beacon al backend propio (carta/track.php)
        window.rrbbTrackBeacon = function(payload) {
            try {
                var bodyObj = Object.assign({
                    source: SOURCE,
                    session_id: sid,
                    utm_source: attr.utm_source || (attr.fbclid ? 'facebook_ads' : (attr.gclid ? 'google_ads' : '')),
                    utm_medium: attr.utm_medium || '',
                    utm_campaign: attr.utm_campaign || ''
                }, payload || {});
                var json = JSON.stringify(bodyObj);
                var blob = new Blob([json], { type: 'application/json' });
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(TRACK_URL, blob);
                } else {
                    fetch(TRACK_URL, { method: 'POST', body: blob, keepalive: true }).catch(function(){});
                }
            } catch (e) {}
        };

        // 4. Despachador unificado de eventos de E-Commerce (Meta Pixel + Google GTM/GA4 + Embudo propio)
        window.rrbbTrack = function(action, data) {
            data = data || {};
            try {
                if (action === 'add_to_cart') {
                    var price = Number(data.price) || 0;
                    var qty   = Number(data.quantity) || 1;
                    var idStr = String(data.id || data.name || 'item');
                    if (typeof window.fbq === 'function') {
                        window.fbq('track', 'AddToCart', {
                            content_ids: [idStr],
                            content_name: String(data.name || ''),
                            content_type: 'product',
                            value: Number((price * qty).toFixed(2)),
                            currency: 'ARS'
                        });
                    }
                    window.dataLayer.push({ ecommerce: null });
                    window.dataLayer.push({
                        event: 'add_to_cart',
                        ecommerce: {
                            currency: 'ARS',
                            value: Number((price * qty).toFixed(2)),
                            items: [{
                                item_id: idStr,
                                item_name: String(data.name || ''),
                                price: price,
                                quantity: qty
                            }]
                        }
                    });
                    window.rrbbTrackBeacon({
                        type: 'funnel',
                        step: 'add_to_cart',
                        item_name: String(data.name || ''),
                        value: Number((price * qty).toFixed(2))
                    });
                } else if (action === 'begin_checkout') {
                    var chkTotal = Number(data.total) || 0;
                    var chkCount = Number(data.itemCount) || 1;
                    if (typeof window.fbq === 'function') {
                        window.fbq('track', 'InitiateCheckout', {
                            value: Number(chkTotal.toFixed(2)),
                            num_items: chkCount,
                            currency: 'ARS'
                        });
                    }
                    window.dataLayer.push({ ecommerce: null });
                    window.dataLayer.push({
                        event: 'begin_checkout',
                        ecommerce: {
                            currency: 'ARS',
                            value: Number(chkTotal.toFixed(2)),
                            items: Array.isArray(data.items) ? data.items : []
                        }
                    });
                    window.rrbbTrackBeacon({
                        type: 'funnel',
                        step: 'open_cart',
                        value: Number(chkTotal.toFixed(2))
                    });
                } else if (action === 'open_pickup_slot') {
                    var stTotal = Number(data.total) || 0;
                    window.dataLayer.push({
                        event: 'add_shipping_info',
                        ecommerce: {
                            currency: 'ARS',
                            value: Number(stTotal.toFixed(2)),
                            shipping_tier: String(data.deliveryType || ''),
                            payment_type: String(data.paymentMethod || '')
                        }
                    });
                    window.rrbbTrackBeacon({
                        type: 'funnel',
                        step: 'open_pickup_slot',
                        value: Number(stTotal.toFixed(2))
                    });
                } else if (action === 'purchase') {
                    var ordTotal = Number(data.total) || 0;
                    var ordCount = Number(data.itemCount) || 1;
                    var eventId  = String(data.orderCode || ('RB-' + Date.now()));
                    var gaItems  = Array.isArray(data.items) ? data.items : [];
                    if (typeof window.fbq === 'function') {
                        window.fbq('track', 'Purchase', {
                            value: Number(ordTotal.toFixed(2)),
                            currency: 'ARS',
                            num_items: ordCount,
                            content_type: 'product',
                            content_ids: gaItems.map(function(i){ return String(i.item_id || i.name || ''); }),
                            contents: gaItems.map(function(i){ return { id: String(i.item_id || i.name || ''), quantity: Number(i.quantity || i.qty || 1) }; })
                        }, { eventID: eventId });
                    }
                    window.dataLayer.push({ ecommerce: null });
                    window.dataLayer.push({
                        event: 'purchase',
                        ecommerce: {
                            transaction_id: eventId,
                            currency: 'ARS',
                            value: Number(ordTotal.toFixed(2)),
                            shipping_tier: String(data.deliveryType || ''),
                            payment_type: String(data.paymentMethod || ''),
                            is_preorder: Boolean(data.isPreorder),
                            items: gaItems
                        }
                    });
                } else if (action === 'contact_whatsapp') {
                    if (typeof window.fbq === 'function') {
                        window.fbq('track', 'Contact', { content_name: 'WhatsApp Directo' });
                    }
                    window.dataLayer.push({ event: 'whatsapp_contact', source: SOURCE });
                }
            } catch (e) {}
        };

        // 5. Vista inicial + tracking automático en links directos a WhatsApp
        document.addEventListener('DOMContentLoaded', function() {
            if (!SKIP_VIEW) {
                window.rrbbTrackBeacon({
                    type: 'view',
                    path: (location.pathname || '/') + (location.search || ''),
                    ref: document.referrer || attr.initial_ref || ''
                });
            }
            document.body.addEventListener('click', function(ev) {
                var a = ev.target && ev.target.closest ? ev.target.closest('a[href*="wa.me"]') : null;
                if (a) {
                    window.rrbbTrack('contact_whatsapp');
                }
            });
        });
    })();
    </script>
