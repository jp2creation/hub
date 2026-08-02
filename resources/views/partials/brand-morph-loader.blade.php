<div id="brand-morph-loader" class="brand-morph-loader" aria-hidden="true">
    <div class="brand-morph-loader__backdrop"></div>

    <div class="brand-morph-loader__stage" role="status" aria-live="polite" aria-label="Chargement">
        <img
            class="brand-morph-loader__image"
            src="{{ \App\Support\CrmAsset::url('modules/crm-core/brand-morph-loader.gif') }}"
            alt=""
            decoding="async"
            data-brand-loader-image
        >
        <p class="brand-morph-loader__message" data-brand-loader-message>Connexion...</p>
        <p class="brand-morph-loader__error" data-brand-loader-error></p>
    </div>
</div>
