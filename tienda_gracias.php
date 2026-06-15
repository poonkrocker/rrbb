<?php
session_start();
$status = $_GET['status'] ?? ($_GET['ref'] ? 'success' : '');
$ref    = $_GET['ref']    ?? '';
$total  = $_GET['total']  ?? '';

$titles = [
    'success' => '¡Gracias por tu compra!',
    'pending' => 'Tu pago está pendiente',
    'failure' => 'El pago no se completó',
    ''        => '¡Gracias por tu compra!',
];
$messages = [
    'success' => 'Recibimos tu pedido y lo estamos preparando. Te contactaremos por los datos que dejaste.',
    'pending' => 'Cuando se acredite el pago, vamos a preparar tu pedido. Si tenés dudas, escribinos por WhatsApp.',
    'failure' => 'No pudimos procesar el pago. Podés intentarlo de nuevo desde la tienda.',
    ''        => 'Recibimos tu pedido. Te contactaremos por los datos que dejaste.',
];
$title = $titles[$status] ?? $titles[''];
$msg   = $messages[$status] ?? $messages[''];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - Arrabbiata</title>
    <link rel="icon" type="image/png" href="/favicon.png"/>
    <link rel="stylesheet" href="arrabbiata.css">
    <style>
        .thanks-section {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            background: repeating-linear-gradient(45deg, var(--ocre) 0 22px, var(--ocre-d) 22px 44px);
        }
        .thanks-card {
            background: var(--blanco);
            border: 3px solid var(--azul-elec);
            border-radius: var(--radius);
            box-shadow: 6px 6px 0 var(--azul-elec);
            max-width: 520px;
            width: 100%;
            padding: 40px 32px;
            text-align: center;
        }
        .thanks-card h1 {
            font-family: var(--font-display);
            color: var(--rojo);
            font-size: 2.2rem;
            margin-bottom: 16px;
            text-shadow: 2px 2px 0 var(--ocre);
        }
        .thanks-card p { font-family: var(--font-body); color: var(--marron); font-size: 1.05rem; line-height: 1.5; }
        .thanks-ref { margin-top: 18px; font-size: .95rem; color: #6b5a45; }
        .thanks-ref strong { color: var(--azul-elec); }
        .thanks-btn {
            display: inline-block;
            margin-top: 26px;
            background: var(--rojo);
            color: #fff;
            border: 2px solid var(--rojo-d);
            border-radius: 6px;
            padding: 12px 26px;
            font-weight: 700;
            font-family: var(--font-body);
            box-shadow: 3px 3px 0 var(--rojo-d);
            transition: all .15s ease;
        }
        .thanks-btn:hover { background: var(--rojo-f); transform: translate(-1px,-1px); box-shadow: 4px 4px 0 var(--rojo-d); }
    </style>
</head>
<body>
    <section class="thanks-section">
        <div class="thanks-card">
            <h1><?php echo htmlspecialchars($title); ?></h1>
            <p><?php echo htmlspecialchars($msg); ?></p>
            <?php if ($ref): ?>
                <p class="thanks-ref">N° de pedido: <strong><?php echo htmlspecialchars($ref); ?></strong>
                <?php if ($total !== ''): ?><br>Total: $<?php echo htmlspecialchars($total); ?><?php endif; ?>
                </p>
            <?php endif; ?>
            <a class="thanks-btn" href="tienda.php">Volver a la tienda</a>
        </div>
    </section>
</body>
</html>
