<?php

namespace Tests\Feature;

use Tests\TestCase;

class CrmTapisRomusUiAssetTest extends TestCase
{
    public function test_tapis_romus_module_uses_the_pdf_order_workflow(): void
    {
        $asset = (string) file_get_contents(base_path('Modules/CrmTapisRomus/resources/assets/crm-tapis-romus.js'));

        $this->assertFileExists(public_path('romus-tapis/pdf-lib.min.js'));
        $this->assertFileExists(public_path('romus-tapis/BON DE COMMANDE TAPIS ROMUS_AOUT2025.pdf'));
        $this->assertFileExists(base_path('Modules/CrmTapisRomus/resources/assets/logo-romus.png'));
        $this->assertStringContainsString('const pdfLibUrl = "/romus-tapis/pdf-lib.min.js"', $asset);
        $this->assertStringContainsString('const templatePdfUrl = "/romus-tapis/BON%20DE%20COMMANDE%20TAPIS%20ROMUS_AOUT2025.pdf"', $asset);
        $this->assertStringContainsString('import romusLogoUrl from "./logo-romus.png";', $asset);
        $this->assertStringContainsString('async function generatePdf()', $asset);
        $this->assertStringContainsString('function fillPdf(form, page, regularFont, boldFont, pdfLib)', $asset);
        $this->assertStringContainsString('PDFDocument.load(existingPdfBytes)', $asset);
        $this->assertStringContainsString('form.flatten()', $asset);
        $this->assertStringContainsString('BON_DE_COMMANDE_TAPIS_ROMUS_rempli.pdf', $asset);

        $this->assertStringContainsString('setRadio("Commande ou devis"', $asset);
        $this->assertStringContainsString('setText(" Raison Sociale"', $asset);
        $this->assertStringContainsString('setText(" Réf Chantier"', $asset);
        $this->assertStringContainsString('setText("ModèleTapis"', $asset);
        $this->assertStringContainsString('setText("RéfTapis"', $asset);
        $this->assertStringContainsString('setText("QuantitéTapis"', $asset);
        $this->assertStringContainsString('setText("Longueur L1"', $asset);
        $this->assertStringContainsString('setText("Profondeur P2"', $asset);
        $this->assertStringContainsString('setCheck("Dimensions exactes du tapis à fabriquer"', $asset);
        $this->assertStringContainsString('setCheck("Dimensions prises à lintérieur du cadre1"', $asset);
        $this->assertStringContainsString('setCheck("Dimensions de la réservation dans laquelle1"', $asset);
        $this->assertStringContainsString('setCheck("Je mettrai un cadre Romus lequel  Réf"', $asset);
        $this->assertStringContainsString('setText("undefined_14"', $asset);
        $this->assertStringContainsString('drawFittedText(page, boldFont, pdfLib, data.numeroCommande, 214, 777.2, 76, 10, 8)', $asset);

        $this->assertStringContainsString('Client et chantier', $asset);
        $this->assertStringContainsString('Articles et quantités', $asset);
        $this->assertStringContainsString('Plan et cadre', $asset);
        $this->assertStringContainsString('Contrôle final', $asset);
        $this->assertStringContainsString('tapis-kpis', $asset);
        $this->assertStringContainsString('tapis-heading', $asset);
        $this->assertStringContainsString('tapis-romus-title-logo', $asset);
        $this->assertStringContainsString('alt="ROMUS"', $asset);
        $this->assertStringContainsString('font-size:1.8rem', $asset);
        $this->assertStringContainsString('font-size:1.55rem', $asset);
        $this->assertStringContainsString('grid-template-columns:2.6rem minmax(0,1fr)', $asset);
        $this->assertStringContainsString('color-mix(in srgb,var(--tapis-kpi-color,var(--tapis-primary)) 14%,white)', $asset);
        $this->assertStringContainsString('.tapis-kpi.is-command', $asset);
        $this->assertStringContainsString('.tapis-kpi.is-references', $asset);
        $this->assertStringContainsString('.tapis-kpi.is-dimensions', $asset);
        $this->assertStringContainsString('.tapis-kpi.is-pdf', $asset);
        $this->assertStringNotContainsString('tapis-romus-mark', $asset);
        $this->assertStringContainsString('clipboardCheck', $asset);
        $this->assertStringContainsString('rulerSquare', $asset);
        $this->assertStringContainsString('tapis-stepper', $asset);
        $this->assertStringContainsString('tapis-actions', $asset);
        $this->assertStringContainsString('type === "generate"', $asset);
        $this->assertStringContainsString('tapisRomusBound', $asset);
        $this->assertStringContainsString('function cssEscape(value)', $asset);
        $this->assertStringContainsString('window.CRM_ACTIVE_SITE', $asset);
        $this->assertStringContainsString('function prefillContactFromSite(options = {})', $asset);
        $this->assertStringContainsString('sitePrefill', $asset);
        $this->assertStringContainsString('profilePrefill', $asset);
        $this->assertStringContainsString('function profileContactValues(profile)', $asset);
        $this->assertStringContainsString('function prefillContactFromProfile(profile = state.profilePrefill.profile, options = {})', $asset);
        $this->assertStringContainsString('function loadProfilePrefill(options = {})', $asset);
        $this->assertStringContainsString('/api/administration?action=profile', $asset);
        $this->assertStringContainsString('profile.firstName', $asset);
        $this->assertStringContainsString('profile.lastName', $asset);
        $this->assertStringContainsString('nom: lastName', $asset);
        $this->assertStringContainsString('prenom: firstName', $asset);
        $this->assertStringContainsString('crm:profile-updated', $asset);
        $this->assertStringContainsString('["nom", "prenom"].includes(field.dataset.field)', $asset);
        $this->assertStringContainsString('Coordonnées préremplies depuis', $asset);
        $this->assertStringContainsString('Reprendre les coordonnées du site', $asset);
        $this->assertStringContainsString('Coordonnées site à compléter', $asset);
        $this->assertStringContainsString('La raison sociale du site est obligatoire.', $asset);
        $this->assertStringContainsString('Le téléphone est invalide.', $asset);
        $this->assertStringContainsString("L'e-mail est invalide.", $asset);
        $this->assertStringContainsString('function isValidPhone(value)', $asset);

        $this->assertStringNotContainsString('Surface et rouleaux estimés', $asset);
        $this->assertStringNotContainsString('surfaceTotal', $asset);
        $this->assertStringNotContainsString('rouleaux', $asset);
    }
}
