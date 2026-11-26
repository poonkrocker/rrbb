<?php
session_start();
require_once dirname(__DIR__) . '/db_connect.php';
require_once dirname(__DIR__) . '/_carta_data.php';

$opening_hours_schema = buildOpeningHoursSchema($all_hours);
$local_schema = [
    '@context'                  => 'https://schema.org',
    '@type'                     => 'Restaurant',
    'name'                      => 'Arrabbiata',
    'image'                     => 'https://arrabbiata.com.ar/wp-content/uploads/2025/05/Identidad-Arrabbiata-19_page-0001-1-scaled-e1746087327655.png',
    'address'                   => [
        '@type'           => 'PostalAddress',
        'streetAddress'   => '27 de abril 798',
        'addressLocality' => 'Córdoba',
        'addressRegion'   => 'Córdoba',
        'postalCode'      => 'X5000',
        'addressCountry'  => 'AR',
    ],
    'geo'                       => [
        '@type'     => 'GeoCoordinates',
        'latitude'  => -31.415915,
        'longitude' => -64.188624,
    ],
    'url'                       => 'https://carta.arrabbiata.com.ar',
    'telephone'                 => '+5493517548030',
    'openingHoursSpecification' => $opening_hours_schema,
    'sameAs'                    => ['https://www.instagram.com/arrabbiata.pizza'],
    'description'               => 'Carta online de Pizzería Arrabbiata — la mejor pizza de Alberdi, Córdoba.',
];

$analytics_source = 'carta';
$track_endpoint   = 'track.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuestra Carta - Pizzería Arrabbiata</title>
    <link rel="icon" type="image/png" href="favicon.png"/>
<?php require dirname(__DIR__) . '/_analytics.php'; ?>
    <link rel="preload" href="rrbb.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="arrabbiata.css">
    <script type="application/ld+json">
<?php echo json_encode($local_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
    </script>
    <style>
        @font-face {
            font-family: 'Alberdini';
            src: url('rrbb.woff2') format('woff2'),
                 url('rrbb.woff') format('woff');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }
        .section { padding-top: 30px !important; }
        .menu-header {
            text-align: center;
            padding: 12px 16px 14px;
            border-bottom: 2px solid var(--rojo, #c0392b);
            margin-bottom: 5px;
        }
        .menu-header img.menu-logo {
            width: clamp(200px, 75vw, 340px);
            height: auto;
            display: block;
            margin: 0 auto 1%;
        }
        .menu-header-info {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px 16px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.75rem;
            color: var(--marron, #222);
        }
        .menu-header-info a { color: inherit; text-decoration: none; white-space: nowrap; }
        .menu-header-info a:hover { text-decoration: underline; }
        .menu-header-info .sep { color: var(--rojo, #c0392b); font-weight: bold; user-select: none; }
    </style>
</head>
<body>
<?php renderAnalyticsBodyNoscript(); ?>
    <section class="section">
        <?php
        $carta_show_brand_header = true;
        include dirname(__DIR__) . '/_carta_menu.php';
        ?>
    </section>

    <?php include dirname(__DIR__) . '/cart_modal.php'; ?>
    <?php include dirname(__DIR__) . '/_variant_modal.php'; ?>

    <footer>
        <div class="footer-content">
            <p>Arrabbiata - La mejor pizza de Alberdi</p>
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

<?php include dirname(__DIR__) . '/_carta_scripts.php'; ?>
</body>
</html>
