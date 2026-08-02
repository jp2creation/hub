<div id="brand-morph-loader" class="brand-morph-loader" aria-hidden="true">
    <div class="brand-morph-loader__backdrop"></div>

    <div class="brand-morph-loader__stage" role="status" aria-live="polite" aria-label="Chargement">
        <video
            class="brand-morph-loader__video"
            src="{{ \App\Support\CrmAsset::url('modules/crm-core/brand-morph-loader.mp4') }}"
            autoplay
            muted
            loop
            playsinline
            preload="auto"
            data-brand-loader-video
        ></video>
        <p class="brand-morph-loader__message" data-brand-loader-message>Connexion...</p>
        <p class="brand-morph-loader__error" data-brand-loader-error></p>
    </div>
</div>
