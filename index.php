<?php
session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/_carta_data.php';

// ===========================================================
// Construcción del Schema.org (JSON-LD) - DINÁMICO
// ===========================================================
$opening_hours_schema = buildOpeningHoursSchema($all_hours);

$schema_menu_sections = [];
try {
    foreach ($categories as $category) {
        if (!empty($secret_category_ids[(int)$category['id']])) continue;

        $stmt = $pdo->prepare("
            SELECT mi.*
            FROM menu_items mi
            WHERE mi.category_id = ?
              AND mi.is_visible = 1
              AND (mi.is_secret_menu IS NULL OR mi.is_secret_menu = 0)
            ORDER BY mi.display_order
        ");
        $stmt->execute([$category['id']]);
        $cat_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($cat_items) === 0) continue;

        $menu_items_schema = [];
        foreach ($cat_items as $mi) {
            $item_schema = [
                '@type' => 'MenuItem',
                'name'  => (string)$mi['name'],
            ];
            if (!empty($mi['description'])) {
                $desc = strip_tags($mi['description']);
                $desc = preg_replace('/\s+/', ' ', $desc);
                $item_schema['description'] = trim($desc);
            }
            if (!empty($mi['image_url'])) {
                $item_schema['image'] = $mi['image_url'];
            }
            if (isset($mi['price']) && $mi['price'] !== null && $mi['price'] !== '') {
                $item_schema['offers'] = [
                    '@type'         => 'Offer',
                    'price'         => number_format((float)$mi['price'], 2, '.', ''),
                    'priceCurrency' => 'ARS',
                    'availability'  => 'https://schema.org/InStock',
                ];
            }
            if (!empty($mi['has_vegan_option'])) {
                $item_schema['suitableForDiet'] = 'https://schema.org/VeganDiet';
            }
            $menu_items_schema[] = $item_schema;
        }

        $schema_menu_sections[] = [
            '@type'       => 'MenuSection',
            'name'        => (string)$category['name'],
            'hasMenuItem' => $menu_items_schema,
        ];
    }
} catch (PDOException $e) {
    error_log("Error armando schema de menú: " . $e->getMessage());
    $schema_menu_sections = [];
}

$restaurant_schema = [
    '@context'            => 'https://schema.org',
    '@type'               => 'Restaurant',
    '@id'                 => 'https://arrabbiata.com.ar/#restaurant',
    'name'                => 'Arrabbiata',
    'alternateName'       => 'Pizzería Arrabbiata',
    'description'         => 'Pizzería Arrabbiata, la mejor pizza de Alberdi. Auténticas pizzas estilo napolitano con ingredientes de la provincia, en el corazón del barrio Alberdi, Córdoba Capital.',
    'url'                 => 'https://arrabbiata.com.ar',
    'logo'                => 'https://arrabbiata.com.ar/Uploads/logolindo.png',
    'image'               => [
        'https://arrabbiata.com.ar/wp-content/uploads/2025/05/Identidad-Arrabbiata-19_page-0001-1-scaled-e1746087327655.png',
        'https://arrabbiata.com.ar/Uploads/Img001.webp',
        'https://arrabbiata.com.ar/Uploads/Img002.webp',
        'https://arrabbiata.com.ar/Uploads/Img003.webp',
        'https://arrabbiata.com.ar/Uploads/Img004.webp',
        'https://arrabbiata.com.ar/Uploads/Img005.webp',
    ],
    'telephone'           => '+5493517548030',
    'email'               => 'prontoarrabbiata@gmail.com',
    'priceRange'          => '$$',
    'servesCuisine'       => ['Italiana', 'Pizza Napolitana'],
    'acceptsReservations' => 'False',
    'paymentAccepted'     => 'Efectivo, Transferencia, Tarjeta de Débito, Tarjeta de Crédito',
    'currenciesAccepted'  => 'ARS',
    'address'             => [
        '@type'           => 'PostalAddress',
        'streetAddress'   => '27 de abril 798',
        'addressLocality' => 'Córdoba',
        'addressRegion'   => 'Córdoba',
        'postalCode'      => 'X5000',
        'addressCountry'  => 'AR',
    ],
    'geo'                 => [
        '@type'     => 'GeoCoordinates',
        'latitude'  => -31.415915,
        'longitude' => -64.188624,
    ],
    'hasMap'              => 'https://maps.app.goo.gl/8QyHubKUr5CT2bC68',
    'openingHoursSpecification' => $opening_hours_schema,
    'sameAs'              => [
        'https://www.instagram.com/arrabbiata.pizza',
        'https://maps.app.goo.gl/8QyHubKUr5CT2bC68',
    ],
];

if (!empty($schema_menu_sections)) {
    $restaurant_schema['hasMenu'] = [
        '@type'          => 'Menu',
        'name'           => 'Carta Arrabbiata',
        'inLanguage'     => 'es-AR',
        'hasMenuSection' => $schema_menu_sections,
    ];
}

$website_schema = [
    '@context'   => 'https://schema.org',
    '@type'      => 'WebSite',
    '@id'        => 'https://arrabbiata.com.ar/#website',
    'name'       => 'Arrabbiata',
    'url'        => 'https://arrabbiata.com.ar',
    'inLanguage' => 'es-AR',
    'publisher'  => ['@id' => 'https://arrabbiata.com.ar/#restaurant'],
];

$json_flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

// ---- Datos de la TIENDA (compartido con tienda.php) ----
require __DIR__ . '/_store_data.php';

$analytics_source = 'home';
$track_endpoint   = 'carta/track.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arrabbiata - la mejor Pizza de Alberdi - Córdoba, Argentina</title>
    <meta name="description" content="Pizzería Arrabbiata, la mejor pizza de Alberdi. Estilo napolitano con ingredientes locales, en 27 de abril 798, barrio Alberdi, Córdoba Capital. Pedí por WhatsApp.">
    <link rel="canonical" href="https://arrabbiata.com.ar/">
<?php require __DIR__ . '/_analytics.php'; ?>
    <!-- Open Graph -->
    <meta property="og:type" content="restaurant.restaurant">
    <meta property="og:locale" content="es_AR">
    <meta property="og:site_name" content="Arrabbiata">
    <meta property="og:title" content="Pizzería Arrabbiata - La mejor pizza de Alberdi, Córdoba">
    <meta property="og:description" content="La mejor pizza de Alberdi. Estilo napolitano, ingredientes locales. 27 de abril 798, Córdoba.">
    <meta property="og:url" content="https://arrabbiata.com.ar/">
    <meta property="og:image" content="https://arrabbiata.com.ar/wp-content/uploads/2025/05/Identidad-Arrabbiata-19_page-0001-1-scaled-e1746087327655.png">
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Pizzería Arrabbiata - La mejor pizza de Alberdi, Córdoba">
    <meta name="twitter:description" content="La mejor pizza de Alberdi, estilo napolitano. En Córdoba Capital.">
    <meta name="twitter:image" content="https://arrabbiata.com.ar/wp-content/uploads/2025/05/Identidad-Arrabbiata-19_page-0001-1-scaled-e1746087327655.png">

    <link rel="preload" href="rrbb.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="arrabbiata.css">
    <link rel="icon" type="image/png" href="/favicon.png"/>

    <!-- Schema JSON-LD: Restaurant (dinámico desde BD) -->
    <script type="application/ld+json">
<?php echo json_encode($restaurant_schema, $json_flags); ?>
    </script>
    <!-- Schema JSON-LD: WebSite -->
    <script type="application/ld+json">
<?php echo json_encode($website_schema, $json_flags); ?>
    </script>

    <?php include __DIR__ . '/_store_styles.php'; ?>
</head>
<body>
<?php renderAnalyticsBodyNoscript(); ?>
<div class="container">
    <div class="main-container">
        <!-- Navigation -->
        <nav class="nav-menu">
            <ul>
                <li><a href="#home">Home</a></li>
                <li><a href="#nosotros">Nosotros</a></li>
                <li><a href="#carta">Carta</a></li>
                <?php if (!empty($store_categories)): ?>
                <li><a href="#tienda">Tienda</a></li>
                <?php endif; ?>
                <li><a href="#ubicacion">Ubicación</a></li>
            </ul>
        </nav>

        <!-- Home Section -->
        <section id="home" class="section active">
            <video class="video-background" autoplay muted loop playsinline>
                <source src="https://arrabbiata.com.ar/Uploads/pizzitaloop.mp4" type="video/mp4">
                Tu navegador no soporta videos HTML5.
            </video>
            <div class="home-content">
                <img src="https://arrabbiata.com.ar/Uploads/logolindo.png"
                     alt="Arrabbiata"
                     class="floating-logo"
                     fetchpriority="high">
                <p>LA MEJOR PIZZA DE ALBERDI</p>
                <a href="#carta" class="hero-cta">🍕 Ver la Carta y Pedir</a>
            </div>
        </section>

        <!-- About Section -->
        <section id="nosotros" class="section" aria-label="La mejor pizza de Alberdi, Córdoba">
            <div class="gallery-container">
                <div class="gallery-slide" style="background-image: url('https://arrabbiata.com.ar/Uploads/Img005.webp');"></div>
                <div class="gallery-slide" style="background-image: url('https://arrabbiata.com.ar/Uploads/Img003.webp');"></div>
                <div class="gallery-slide" style="background-image: url('https://arrabbiata.com.ar/Uploads/Img002.webp');"></div>
                <div class="gallery-slide" style="background-image: url('https://arrabbiata.com.ar/Uploads/Img001.webp');"></div>
                <div class="gallery-slide" style="background-image: url('https://arrabbiata.com.ar/Uploads/Img004.webp');"></div>
            </div>

            <div class="nosotros-text">
                <h2>Que hacemos?</h2>
                <p>Hacemos pizzas estilo napolitano en un sentido muy amplio del término. Nuestros castigadisimos hornitos eléctricos tuneados nos permiten hacer un estilo propio de inspiración napolitana pero de pizzas auténticas y originales. Somos obsesivos en la búsqueda de los mejores ingredientes y tratamos siempre de usar materias primas locales de la provincia de Córdoba, porque realmente creemos que es importante darle valor a las maravillas que crecen en nuestra tierra. Cada semana proponemos una nueva pizza y estamos constantemente en la búsqueda de mejorar nuestra propuesta con una profunda atención al detalle.<br /><br /></p>
            </div>
        </section>

        <!-- Menu Section (100% unificada con /carta) -->
        <section id="carta" class="section">
            <?php
            $carta_show_brand_header = false;
            include __DIR__ . '/_carta_menu.php';
            ?>
        </section>

        <!-- Tienda Section -->
        <?php if (!empty($store_categories)): ?>
        <section id="tienda" class="section">
            <div class="menu-container">
                <?php include __DIR__ . '/_store_section.php'; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Modales compartidos de Carta y Variantes -->
        <?php include __DIR__ . '/cart_modal.php'; ?>
        <?php include __DIR__ . '/_variant_modal.php'; ?>

        <!-- Floating Store Cart -->
        <?php if (!empty($store_categories)): ?>
            <?php include __DIR__ . '/_store_cart.php'; ?>
        <?php endif; ?>

        <!-- Location Section -->
        <section id="ubicacion" class="section">
            <div class="contact-info">
                <h2>Ubicación</h2>
                <div class="map-container">
                    <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3405.0074799324966!2d-64.197666125057!3d-31.413919996130915!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x943299025f8e592f%3A0xa204fab89226789c!2sArrabbiata%20Pizza%20C%C3%B3rdoba!5e0!3m2!1ses!2sar!4v1747767861650!5m2!1ses!2sar" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
                <h3>Dirección</h3>
                <p>27 de abril 798, barrio Alberdi, Córdoba Capital</p>

                <h3>Horarios</h3>
                <?php
                if (!empty($all_hours)) {
                    $horarios_formateados = formatGroupedHours($all_hours);
                    foreach (explode('<br>', $horarios_formateados) as $linea) {
                        $linea = trim($linea);
                        if ($linea !== '') {
                            echo '<p>' . htmlspecialchars($linea, ENT_QUOTES, 'UTF-8') . '</p>';
                        }
                    }
                } else {
                    echo '<p>Horarios no configurados, contacta al administrador.</p>';
                }
                ?>

                <h3>Contacto</h3>
                <a href="https://www.instagram.com/arrabbiata.pizza" target="_blank" rel="noopener">@arrabbiata.pizza</a>
                <a href="mailto:prontoarrabbiata@gmail.com">prontoarrabbiata@gmail.com</a>
                <br><br>
            </div>
        </section>
    </div>

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
</div>

<?php include __DIR__ . '/_carta_scripts.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Rotación de galería en sección Nosotros
    const slides = document.querySelectorAll('.gallery-slide');
    if (slides.length > 0) {
        let currentSlide = 0;
        function showSlide(n) {
            slides.forEach(s => s.classList.remove('active'));
            slides[n].classList.add('active');
        }
        showSlide(0);
        setInterval(function() {
            currentSlide = (currentSlide + 1) % slides.length;
            showSlide(currentSlide);
        }, 5000);
    }

    // Smooth scroll de la barra superior y botón principal
    document.querySelectorAll('.nav-menu a[href^="#"], .hero-cta[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

    // Animación de secciones al scrollear
    const sections = document.querySelectorAll('.section');
    function checkScroll() {
        sections.forEach(section => {
            const rect = section.getBoundingClientRect();
            if (rect.top < window.innerHeight * 0.7 && rect.top > -section.offsetHeight * 0.7) {
                section.classList.add('active');
            } else {
                section.classList.remove('active');
            }
        });
    }
    window.addEventListener('load', checkScroll);
    window.addEventListener('scroll', checkScroll, { passive: true });
});
</script>
</body>
</html>
