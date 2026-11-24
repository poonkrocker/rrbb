<?php
/**
 * _variant_modal.php — Modal para elegir VARIANTES con precio propio.
 *
 * Partial compartido. Incluir UNA vez por página, después de _variants_data.php
 * (necesita $variants_by_item en el scope) y junto al resto de los modales.
 *
 * Depende de dos globals que ya existen en carta.php / index.php:
 *   - const isOpen (bool)
 *   - function addToCart(name, price, itemId, isVegan, subproducts, dep, variantId)
 *
 * Reutiliza el #overlay existente de la página (no crea uno nuevo).
 */
$variants_by_item = $variants_by_item ?? [];
?>
<div id="variantModal" class="variant-modal">
    <div class="variant-modal-header">
        <h3 id="variantModalTitle">Elegí tu variante</h3>
        <button type="button" class="variant-modal-close" onclick="closeVariantModal()" aria-label="Cerrar">&times;</button>
    </div>
    <div id="variantModalOptions" class="variant-modal-options"></div>
</div>
<!-- Estilos del modal y de las pills: en arrabbiata.css -->

<script>
    // Mapa item_id => [variantes]. Lo emite PHP una sola vez.
    window.__variantsByItem = <?php echo json_encode($variants_by_item, JSON_UNESCAPED_UNICODE); ?> || {};

    // --- Pills inline (1 a 3 variantes) ---------------------------------
    // Elegir una pill: marca activa y actualiza el precio mostrado. NO agrega
    // al carrito (eso pasa al tocar la tarjeta, como el resto de los productos).
    function selectVariant(pill){
        const picker = pill.closest('.variant-picker');
        if (!picker) return;
        picker.querySelectorAll('.variant-pill').forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
        const price = Number(pill.dataset.variantPrice);
        const card = pill.closest('.pizza-item');
        let extrasTotal = 0;
        if (card) {
            const activePills = card.querySelectorAll('.extra-pill.active');
            activePills.forEach(ep => {
                extrasTotal += parseFloat(ep.dataset.extraPrice) || 0;
            });
        }
        const finalPrice = price + extrasTotal;
        const priceEl = picker.querySelector('.variant-current-price') || card?.querySelector('.item-price');
        if (priceEl){
            priceEl.dataset.price = finalPrice;
            priceEl.textContent = finalPrice.toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
        }
        const descEl = picker.querySelector('.variant-desc');
        if (descEl){ descEl.textContent = pill.dataset.variantDesc || ''; }
    }

    // Agregar la variante seleccionada al tocar la tarjeta.
    function addSelectedVariant(cardEl){
        if (typeof isOpen !== 'undefined' && !isOpen && typeof isPreorder === 'undefined'){
            alert('Lo sentimos, estamos cerrados en este momento.');
            return;
        }
        const active = cardEl.querySelector('.variant-pill.active') ||
                       cardEl.querySelector('.variant-pill');
        if (!active) return;
        const baseName = cardEl.dataset.itemName || '';
        const itemId   = cardEl.dataset.itemId;
        const vName    = active.dataset.variantName;
        const vPrice   = Number(active.dataset.variantPrice);
        const vId      = active.dataset.variantId;
        addToCart(baseName + ' — ' + vName, vPrice, String(itemId), false, [], null, 'v' + vId);
    }

    function openVariantModal(itemId){
        if (typeof isOpen !== 'undefined' && !isOpen){
            alert('Lo sentimos, estamos cerrados en este momento.');
            return;
        }
        const variants = window.__variantsByItem[itemId] || [];
        if (!variants.length) return;

        const card = document.querySelector('.pizza-item[data-item-id="' + itemId + '"]');
        const baseName = card ? card.dataset.itemName : '';

        document.getElementById('variantModalTitle').textContent = baseName || 'Elegí tu variante';

        const opts = document.getElementById('variantModalOptions');
        opts.innerHTML = '';
        variants.forEach(v => {
            const price = Number(v.price);
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'variant-option';
            row.innerHTML =
                '<span class="variant-option-main">' +
                    '<span class="variant-option-name"></span>' +
                    (v.description ? '<span class="variant-option-desc"></span>' : '') +
                '</span>' +
                '<span class="variant-option-price">$' +
                    price.toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2}) +
                '</span>';
            // Texto por separado para evitar inyección desde nombre/descr de la BD.
            row.querySelector('.variant-option-name').textContent = v.name;
            if (v.description) row.querySelector('.variant-option-desc').textContent = v.description;

            row.addEventListener('click', function(){
                const displayName = baseName + ' — ' + v.name;
                // Firma compatible con carta.php (variantId) e index.php (lo ignora).
                addToCart(displayName, price, String(itemId), false, [], null, 'v' + v.id);
                closeVariantModal();
            });
            opts.appendChild(row);
        });

        const modal = document.getElementById('variantModal');
        modal.style.display = 'block';
        modal.classList.add('active');
        const ov = document.getElementById('overlay');
        if (ov){ ov.style.display = 'block'; ov.classList.add('active'); }
    }

    function closeVariantModal(){
        const modal = document.getElementById('variantModal');
        modal.style.display = 'none';
        modal.classList.remove('active');
        const ov = document.getElementById('overlay');
        if (ov){ ov.style.display = 'none'; ov.classList.remove('active'); }
    }
</script>