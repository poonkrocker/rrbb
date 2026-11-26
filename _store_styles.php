<style>
    /* ===== Estilos propios de la tienda (heredan la paleta del sitio) ===== */

    /* El BOTÓN flotante del carrito de tienda va en la esquina izquierda para no
       superponerse con el carrito de la carta cuando ambos conviven en index.php. */
    #storeCartContainer { left: 24px; right: auto; }
    @media (max-width: 480px) {
        #storeCartContainer { left: 12px; }
    }

    .store-card { cursor: pointer; }
    .store-cart-modal .checkout-section { margin-top: 12px; }

    .store-cart-modal .qty-controls {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .store-cart-modal .qty-controls button {
        width: 26px; height: 26px;
        border: 2px solid var(--azul-elec);
        background: #fff;
        color: var(--azul-elec);
        border-radius: 6px;
        font-weight: 700;
        cursor: pointer;
        line-height: 1;
    }
    .store-cart-modal .qty-controls button:hover {
        background: var(--azul-elec);
        color: #fff;
    }
    .store-cart-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        padding: 8px 0;
        border-bottom: 1px dashed var(--ocre-d);
        font-size: 0.92rem;
    }
    .store-cart-item .sci-name { flex: 1; font-weight: 600; color: var(--marron); }
    .store-cart-item.store-shipping-row {
        color: var(--azul-elec);
        font-size: 0.88rem;
    }
    .store-shipping-note {
        display: none;
        margin-top: 8px;
        padding: 8px 10px;
        background: #fef9e7;
        border: 1.5px solid var(--amarillo);
        border-radius: 6px;
        font-size: 0.84rem;
        color: #7c4a03;
    }
    .store-empty-note {
        text-align: center;
        padding: 60px 20px;
        color: var(--marron);
        font-size: 1.1rem;
    }
    .store-empty-note strong { color: var(--rojo); }

    /* Producto agotado */
    .store-soldout { opacity: .55; pointer-events: none; }
    .store-soldout-label {
        position: absolute;
        top: 8px; left: 8px;
        background: var(--rojo);
        color: #fff;
        font-size: .75rem;
        font-weight: 700;
        padding: 3px 8px;
        border-radius: 4px;
        z-index: 2;
    }
</style>
