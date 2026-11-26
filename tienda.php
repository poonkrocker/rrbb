<?php
session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/_visibility.php';

date_default_timezone_set('America/Argentina/Cordoba');
$current_time = date('H:i:s');
$current_day  = date('l');
$day_map = [
    'Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves',
    'Friday'=>'Viernes','Saturday'=>'Sábado','Sunday'=>'Domingo',
];
$current_day_es = $day_map[$current_day] ?? 'Lunes';
$is_open = true;

require __DIR__ . '/_store_data.php';

$analytics_source = 'tienda';
$track_endpoint   = 'carta/track.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tienda - Pizzería Arrabbiata</title>
    <link rel="icon" type="image/png" href="/favicon.png"/>
<?php require __DIR__ . '/_analytics.php'; ?>
    <link rel="preload" href="rrbb.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="arrabbiata.css">
    <?php include __DIR__ . '/_store_styles.php'; ?>
</head>
<body>
<?php renderAnalyticsBodyNoscript(); ?>
    <nav class="nav-menu">
        <ul>
            <li><a href="index.php#home">Home</a></li>
            <li><a href="index.php#carta">Carta</a></li>
            <li><a href="tienda.php">Tienda</a></li>
            <li><a href="index.php#ubicacion">Ubicación</a></li>
        </ul>
    </nav>

    <section class="section" style="align-items:flex-start; padding-top:96px;">
        <div class="menu-container">
            <?php include __DIR__ . '/_store_section.php'; ?>
        </div>
    </section>

    <?php include __DIR__ . '/_store_cart.php'; ?>

    <footer>
        <div class="footer-content">
            <p>Arrabbiata - La mejor pizza de Alberdi, Córdoba</p>
            <div class="footer-social-icons">
                <a href="https://www.instagram.com/arrabbiata.pizza" target="_blank" rel="noopener">
                    <img src="https://arrabbiata.com.ar/ig50.png" alt="Instagram">
                </a>
                <a href="https://maps.app.goo.gl/8QyHubKUr5CT2bC68" target="_blank" rel="noopener">
                    <img src="https://arrabbiata.com.ar/gmaps50.png" alt="Google Maps">
                </a>
                <a href="https://wa.me/5493517548030?text=Hola%20Arrabbiata%2C%20me%20comunico%20desde%20su%20web%20para%20" target="_blank" rel="noopener">
                    <img src="https://arrabbiata.com.ar/wp50.png" alt="Whatsapp">
                </a>
            </div>
        </div>
    </footer>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        function syncNav() {
            var nav = document.querySelector('.nav-menu');
            if (nav) {
                document.documentElement.style.setProperty('--sticky-submenu-top', Math.ceil(nav.getBoundingClientRect().bottom + 6) + 'px');
            }
        }
        syncNav();
        window.addEventListener('resize', syncNav);
    });
    </script>
</body>
</html>
