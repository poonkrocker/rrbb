<?php
/**
 * _variant_modal.php — Modal para elegir VARIANTES (>3 opciones) con precio propio.
 * Habilitado tanto en horario de atención como fuera de horario (pedidos programados).
 */
$variants_by_item = $variants_by_item ?? [];
?>
<div id="variantModal" class="variant-modal">
    <div class="variant-modal-header">
        <h3 id="variantModalTitle">Elegí tu variante</h3>
        <button type="button" class="variant-modal-close" onclick="closeVariantModal()" aria-label="Cerrar">&times;</button>
    </div>
    <div id="variantModalVeganWrap" style="display:none; margin-bottom:10px;">
        <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.88rem; font-weight:600; color:#15803d; cursor:pointer;">
            <input type="checkbox" id="variantModalVeganCheck"> 🌿 Pedir en versión vegana
        </label>
    </div>
    <div id="variantModalOptions" class="variant-modal-options"></div>
</div>

<script>
    window.__variantsByItem = <?php echo json_encode($variants_by_item, JSON_UNESCAPED_UNICODE); ?> || {};

    function openVariantModal(itemId, forceVegan) {
        const variants = window.__variantsByItem[itemId] || [];
        if (!variants.length) return;

        const card = document.querySelector('.pizza-item[data-item-id="' + itemId + '"]');
        const baseName = card ? (card.dataset.itemName || '') : '';
        const hasVegan = card ? (card.dataset.hasVegan === 'true') : false;

        document.getElementById('variantModalTitle').textContent = baseName || 'Elegí tu variante';

        const veganWrap  = document.getElementById('variantModalVeganWrap');
        const veganCheck = document.getElementById('variantModalVeganCheck');
        if (veganWrap && veganCheck) {
            veganWrap.style.display = hasVegan ? 'block' : 'none';
            veganCheck.checked = Boolean(hasVegan && forceVegan);
        }

        const opts = document.getElementById('variantModalOptions');
        opts.innerHTML = '';
        variants.forEach(function(v) {
            const price = Math.round((Number(v.price) || 0) * 100) / 100;
            const digits = Number.isInteger(price) ? 0 : 2;
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'variant-option';
            row.innerHTML =
                '<span class="variant-option-main">' +
                    '<span class="variant-option-name"></span>' +
                    (v.description ? '<span class="variant-option-desc"></span>' : '') +
                '</span>' +
                '<span class="variant-option-price">$' +
                    price.toLocaleString('es-AR', { minimumFractionDigits: digits, maximumFractionDigits: digits }) +
                '</span>';
            row.querySelector('.variant-option-name').textContent = v.name;
            if (v.description) row.querySelector('.variant-option-desc').textContent = v.description;

            row.addEventListener('click', function() {
                const isVeg = Boolean(hasVegan && veganCheck && veganCheck.checked);
                let displayName = baseName + ' — ' + v.name;
                if (isVeg) displayName += ' (Vegana)';
                addToCart(displayName, price, String(itemId), isVeg, 'v' + v.id);
                if (card) {
                    const toast = card.querySelector('.added-toast');
                    if (toast) {
                        toast.classList.add('show');
                        setTimeout(function() { toast.classList.remove('show'); }, 1200);
                    }
                }
                closeVariantModal();
            });
            opts.appendChild(row);
        });

        const modal = document.getElementById('variantModal');
        modal.style.display = 'block';
        modal.classList.add('active');
        const ov = document.getElementById('overlay');
        if (ov) { ov.style.display = 'block'; ov.classList.add('active'); }
    }

    function closeVariantModal() {
        const modal = document.getElementById('variantModal');
        if (!modal) return;
        modal.style.display = 'none';
        modal.classList.remove('active');
        const cartModal = document.getElementById('cartModal');
        const pickupModal = document.getElementById('pickupTimeModal');
        const anyOtherOpen = (cartModal && cartModal.classList.contains('active')) ||
                             (pickupModal && pickupModal.classList.contains('active'));
        if (!anyOtherOpen) {
            const ov = document.getElementById('overlay');
            if (ov) { ov.style.display = 'none'; ov.classList.remove('active'); }
        }
    }
</script>
