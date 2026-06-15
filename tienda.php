<?php
session_start();
require_once 'db_connect.php';
require_once '_visibility.php';

// Zona horaria y día/hora actual (para filtro de visibilidad por franjas)
date_default_timezone_set('America/Argentina/Cordoba');
$current_time = date('H:i:s');
$current_day  = date('l');
$day_map = array(
    'Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves',
    'Friday'=>'Viernes','Saturday'=>'Sábado','Sunday'=>'Domingo'
);
$current_day_es = isset($day_map[$current_day]) ? $day_map[$current_day] : 'Lunes';

// La tienda está disponible siempre (no depende del horario del local)
$is_open = true;

// Carga categorías + productos de tienda (compartido con index.php)
require '_store_data.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tienda - Pizzería Arrabbiata</title>
    <link rel="icon" type="image/png" href="/favicon.png"/>
    <link rel="stylesheet" href="arrabbiata.css">
    <?php include '_store_styles.php'; ?>
</head>
<body>
    <nav class="nav-menu">
        <ul>
            <li><a href="index.php#home">Home</a></li>
            <li><a href="index.php#carta">Carta</a></li>
            <li><a href="tienda.php">Tienda</a></li>
            <li><a href="index.php#ubicacion">Ubicación</a></li>
        </ul>
    </nav>

    <section class="section">
        <div class="menu-container">
            <?php include '_store_section.php'; ?>
        </div>
    </section>

    <?php include '_store_cart.php'; ?>

    <footer>
        <div class="footer-content">
            <p>Arrabbiata - La mejor pizza de Córdoba</p>
            <div class="footer-social-icons">
                <a href="https://www.instagram.com/arrabbiata.pizza" target="_blank">
                    <img src="https://arrabbiata.com.ar/ig50.png" alt="Instagram">
                </a>
                <a href="https://maps.app.goo.gl/8QyHubKUr5CT2bC68" target="_blank">
                    <img src="https://arrabbiata.com.ar/gmaps50.png" alt="Google Maps">
                </a>
                <a href="https://wa.me/5493517548030?text=Hola%20Arrabbiata%2C%20me%20comunico%20desde%20su%20web%20para%20" target="_blank">
                    <img src="https://arrabbiata.com.ar/wp50.png" alt="Whatsapp">
                </a>
            </div>
        </div>
    </footer>
</body>
</html>
