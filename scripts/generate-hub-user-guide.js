import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');
const docsDir = path.join(rootDir, 'docs');
const assetsDir = path.join(docsDir, 'user-guide-assets');
const htmlPath = path.join(docsDir, 'guide-utilisation-hub-martin-sols.html');
const pdfPath = path.join(docsDir, 'guide-utilisation-hub-martin-sols.pdf');

const baseUrl = (process.env.CRM_DOC_BASE_URL || 'https://crm.jp2.fr').replace(/\/$/, '');
const email = process.env.CRM_DOC_EMAIL;
const password = process.env.CRM_DOC_PASSWORD;

if (!email || !password) {
  console.error('Renseigne CRM_DOC_EMAIL et CRM_DOC_PASSWORD pour capturer les pages connectees.');
  process.exit(1);
}

const generatedAt = '15 juillet 2026';
const martinRed = '#a90034';
const martinYellow = '#f5c400';
const ink = '#16283a';
const muted = '#64748b';

const desktopViewport = { width: 1366, height: 900 };
const mobileViewport = { width: 390, height: 844 };

const captures = [
  {
    key: 'login',
    title: 'Page de connexion',
    route: '/login',
    public: true,
    caption: 'L’ecran de connexion reste volontairement simple : email, mot de passe, puis acces direct au HUB.',
  },
  {
    key: 'dashboard',
    title: 'Tableau de bord',
    route: '/',
    caption: 'La page d’accueil regroupe les indicateurs importants et les raccourcis vers les modules du jour.',
    waitForText: 'Tableau de bord',
  },
  {
    key: 'menu-documents',
    title: 'Menu lateral et documents',
    route: '/documents/promo',
    caption: 'Le menu de gauche organise les modules par famille : applications, comptabilite, documents et administration.',
    afterLoad: async (page) => {
      await openSidebarGroup(page, ['Documents', 'DOCUMENTS']);
      await page.waitForTimeout(400);
    },
  },
  {
    key: 'reservations',
    title: 'Reservations vehicules',
    route: '/reservations',
    caption: 'Les vehicules se selectionnent par carte, puis le planning permet de poser ou consulter une reservation.',
    waitForText: 'Reservations',
  },
  {
    key: 'locations-materiel',
    title: 'Location materiel',
    route: '/locations-materiel',
    caption: 'Les cartes materiel affichent le visuel et la disponibilite ; le planning apparait apres selection.',
    waitForText: 'Location',
  },
  {
    key: 'conges',
    title: 'Conges',
    route: '/conges',
    caption: 'Le planning des conges reprend la charte CRM et montre les absences par site, utilisateur et couleur.',
    waitForText: 'Conges',
  },
  {
    key: 'rapport-visite',
    title: 'Rapport de visite commerciaux',
    route: '/rapport-visite',
    caption: 'Le rapport de visite permet aux commerciaux de preparer leurs tournees, suivre les visites clients et saisir le compte rendu.',
    waitSelector: '#crm-sales-tours-module',
    waitForText: 'Rapport de visite',
    delay: 1400,
  },
  {
    key: 'cash-dashboard',
    title: 'Controle caisse - tableau de bord',
    route: '/controle-caisse',
    caption: 'Le tableau de bord caisse donne une vision rapide des caisses, ecarts et montants du site.',
    waitForText: 'Controle',
  },
  {
    key: 'cash-list',
    title: 'Controle caisse - liste',
    route: '/controle-caisse?view=list',
    caption: 'La liste des caisses se filtre par mois et annee, avec les actions de consultation, modification et suppression.',
    waitForText: 'caisses',
  },
  {
    key: 'deposit-requests',
    title: "Demandes d'acompte",
    route: '/demandes-acompte',
    caption: "Les demandes d'acompte permettent de suivre les demandes du site, leur statut, le demandeur et les montants.",
    waitForText: "Demande d'acompte",
  },
  {
    key: 'check-remittances',
    title: 'Remise de cheques',
    route: '/remise-cheques',
    caption: 'Les remises regroupent les cheques, le nombre de pieces et le total avant impression ou PDF.',
    waitForText: 'cheques',
  },
  {
    key: 'documents-promo',
    title: 'Documents - Promo',
    route: '/documents/promo',
    caption: 'Les documents se classent par categorie, dossier, site et visibilite pour retrouver rapidement les fichiers.',
    waitSelector: '#crm-documents-module',
  },
  {
    key: 'tapis-romus',
    title: 'Tapis ROMUS',
    route: '/tapis-romus',
    caption: 'Le module ROMUS permet de saisir les informations de tapis et de generer un bon PDF integre au HUB.',
    waitForText: 'ROMUS',
  },
  {
    key: 'account-settings',
    title: 'Profil utilisateur',
    route: '/pages/account-settings',
    caption: 'La page profil permet de garder les informations personnelles coherentes avec le menu du haut.',
    waitForText: 'Informations personnelles',
  },
];

const mobileCaptures = [
  {
    key: 'mobile-dashboard',
    title: 'Version smartphone - tableau de bord',
    route: '/',
    caption: 'Sur smartphone, le HUB conserve la barre du haut et utilise le menu hamburger pour garder l’ecran lisible.',
  },
  {
    key: 'mobile-location',
    title: 'Version smartphone - location materiel',
    route: '/locations-materiel',
    caption: 'Les cartes materiel restent accessibles en format compact, pense pour une utilisation en boutique ou chantier.',
  },
];

function urlFor(route) {
  if (route.startsWith('http')) {
    return route;
  }

  return `${baseUrl}${route}`;
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function relAsset(filePath) {
  return path.relative(docsDir, filePath).replaceAll('\\', '/');
}

async function ensureDirs() {
  await fs.mkdir(assetsDir, { recursive: true });
}

async function openSidebarGroup(page, labels) {
  for (const label of labels) {
    const button = page.locator(`aside button:has-text("${label}")`).first();
    if (await button.count()) {
      await button.click({ timeout: 1500 }).catch(() => {});
      return;
    }
  }
}

async function waitAfterNavigation(page, capture) {
  await page.waitForLoadState('domcontentloaded', { timeout: 15000 }).catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

  if (capture.waitSelector) {
    await page.waitForSelector(capture.waitSelector, { timeout: 10000 }).catch(() => {});
  }

  if (capture.waitForText) {
    await page.getByText(capture.waitForText, { exact: false }).first().waitFor({ timeout: 8000 }).catch(() => {});
  }

  if (capture.afterLoad) {
    await capture.afterLoad(page);
  }

  await page.waitForTimeout(capture.delay ?? 1000);
}

async function capturePage(page, capture, prefix = 'desktop') {
  const imagePath = path.join(assetsDir, `${prefix}-${capture.key}.jpg`);

  try {
    await page.goto(urlFor(capture.route), { waitUntil: 'domcontentloaded', timeout: 30000 });
    await waitAfterNavigation(page, capture);

    await page.addStyleTag({
      content: `
        *, *::before, *::after { transition-duration: 0s !important; animation-duration: 0s !important; }
        .crisp-client, iframe[title*="chat" i] { display: none !important; }
      `,
    }).catch(() => {});

    await page.screenshot({
      path: imagePath,
      type: 'jpeg',
      quality: 84,
      fullPage: false,
    });

    console.log(`Capture OK: ${capture.title}`);

    return {
      ...capture,
      imagePath,
      imageRel: relAsset(imagePath),
      ok: true,
    };
  } catch (error) {
    console.warn(`Capture impossible: ${capture.title} - ${error.message}`);

    return {
      ...capture,
      ok: false,
      error: error.message,
    };
  }
}

async function login(page) {
  await page.goto(urlFor('/login'), { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await page.fill('input[name="email"], input[type="email"]', email);
  await page.fill('input[name="password"], input[type="password"]', password);

  const submit = page.locator('button[type="submit"], button:has-text("Se connecter")').first();
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 20000 }).catch(() => {}),
    submit.click(),
  ]);

  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

  if (new URL(page.url()).pathname.includes('/login')) {
    throw new Error('La connexion CRM a echoue : verifier CRM_DOC_EMAIL / CRM_DOC_PASSWORD.');
  }
}

function figure(capture, mode = 'wide') {
  if (!capture.ok) {
    return `
      <div class="capture-missing">
        <strong>Capture indisponible : ${escapeHtml(capture.title)}</strong>
        <span>${escapeHtml(capture.error || 'La page n’a pas pu etre capturee pendant la generation.')}</span>
      </div>
    `;
  }

  return `
    <figure class="${mode === 'mobile' ? 'figure-mobile' : ''}">
      <img src="${escapeHtml(capture.imageRel)}" alt="${escapeHtml(capture.title)}" />
      <figcaption>${escapeHtml(capture.caption || capture.title)}</figcaption>
    </figure>
  `;
}

function findCapture(captured, key) {
  return captured.find((capture) => capture.key === key) || { key, title: key, ok: false };
}

function callout(title, body) {
  return `
    <div class="callout">
      <strong>${escapeHtml(title)}</strong>
      <span>${escapeHtml(body)}</span>
    </div>
  `;
}

function steps(items) {
  return `
    <ol class="steps">
      ${items.map((item) => `<li>${item}</li>`).join('\n')}
    </ol>
  `;
}

function bullets(items) {
  return `
    <ul class="bullets">
      ${items.map((item) => `<li>${item}</li>`).join('\n')}
    </ul>
  `;
}

function moduleCard(title, body) {
  return `<div class="module-card"><strong>${escapeHtml(title)}</strong><span>${escapeHtml(body)}</span></div>`;
}

function renderHtml(captured, capturedMobile) {
  const cap = (key) => findCapture(captured, key);
  const mobile = (key) => findCapture(capturedMobile, key);

  return `<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Guide d'utilisation du HUB Martin Sols</title>
  <style>
    @page { size: A4; margin: 12mm 10mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: ${ink};
      font-family: "DM Sans", "Segoe UI", Arial, sans-serif;
      font-size: 10.5pt;
      line-height: 1.45;
      background: #fff;
    }
    h1, h2, h3, p { margin-top: 0; }
    h1 {
      font-size: 34pt;
      line-height: 1.02;
      letter-spacing: 0;
      margin-bottom: 10mm;
    }
    h2 {
      color: ${ink};
      font-size: 18pt;
      line-height: 1.12;
      margin: 0 0 4mm;
      padding-top: 2mm;
    }
    h3 {
      font-size: 12.5pt;
      margin: 5mm 0 2mm;
    }
    p { color: #334155; margin-bottom: 3.2mm; }
    a { color: ${martinRed}; text-decoration: none; }
    .cover {
      min-height: 273mm;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 12mm;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      background:
        linear-gradient(135deg, rgba(169,0,52,.08), rgba(245,196,0,.16)),
        #fff;
      page-break-after: always;
    }
    .cover-mark {
      width: 22mm;
      height: 22mm;
      border-radius: 50%;
      background:
        linear-gradient(90deg, ${martinYellow} 0 34%, #fff 34% 66%, ${martinYellow} 66% 100%),
        ${martinYellow};
      box-shadow: inset 0 0 0 2px rgba(169,0,52,.08);
      position: relative;
      overflow: hidden;
    }
    .cover-mark::before,
    .cover-mark::after {
      content: "";
      position: absolute;
      width: 50%;
      height: 50%;
      background: ${martinRed};
    }
    .cover-mark::before { left: 0; top: 0; }
    .cover-mark::after { right: 0; bottom: 0; }
    .cover-kicker {
      color: ${martinRed};
      font-size: 10pt;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
      margin: 11mm 0 4mm;
    }
    .cover-subtitle {
      max-width: 150mm;
      color: #475569;
      font-size: 14pt;
      line-height: 1.45;
    }
    .cover-meta {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 4mm;
    }
    .cover-box, .toc, .quick-grid, .module-grid, .faq-grid {
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: #fff;
    }
    .cover-box {
      padding: 5mm;
      min-height: 22mm;
    }
    .cover-box strong {
      display: block;
      color: ${martinRed};
      font-size: 8pt;
      letter-spacing: .05em;
      text-transform: uppercase;
      margin-bottom: 1.5mm;
    }
    .cover-box span { color: ${ink}; font-weight: 800; }
    .section {
      break-before: page;
      padding-top: 1mm;
    }
    .section.compact { break-before: auto; }
    .toc {
      padding: 6mm;
      margin: 0 0 6mm;
      display: grid;
      gap: 2.5mm;
    }
    .toc a {
      display: flex;
      justify-content: space-between;
      gap: 8mm;
      border-bottom: 1px dashed #e2e8f0;
      padding-bottom: 1.5mm;
      color: ${ink};
      font-weight: 700;
    }
    .intro-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 4mm;
      margin: 5mm 0;
    }
    .quick-grid, .module-grid, .faq-grid {
      display: grid;
      gap: 4mm;
      padding: 5mm;
    }
    .quick-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .module-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .faq-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .module-card {
      padding: 4mm;
      border: 1px solid #edf2f7;
      border-radius: 7px;
      background: #f8fafc;
      min-height: 31mm;
    }
    .module-card strong {
      display: block;
      color: ${martinRed};
      font-size: 10.2pt;
      margin-bottom: 1.5mm;
    }
    .module-card span { color: #475569; }
    .callout {
      display: grid;
      grid-template-columns: 34mm 1fr;
      gap: 4mm;
      align-items: start;
      margin: 4mm 0;
      padding: 4mm;
      border-left: 4px solid ${martinRed};
      border-radius: 7px;
      background: #fff7fa;
    }
    .callout strong {
      color: ${martinRed};
      text-transform: uppercase;
      letter-spacing: .04em;
      font-size: 8pt;
    }
    .callout span { color: #334155; }
    .steps, .bullets {
      margin: 3mm 0 5mm;
      padding-left: 6mm;
    }
    .steps li, .bullets li {
      margin-bottom: 1.6mm;
      color: #334155;
    }
    figure {
      margin: 4mm 0 6mm;
      break-inside: avoid;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      overflow: hidden;
      background: #fff;
      box-shadow: 0 8px 18px rgba(15, 23, 42, .06);
    }
    figure img {
      display: block;
      width: 100%;
      height: auto;
    }
    figcaption {
      padding: 2.5mm 3.5mm;
      color: #64748b;
      font-size: 8.5pt;
      border-top: 1px solid #e2e8f0;
      background: #f8fafc;
    }
    .mobile-pair {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 5mm;
      align-items: start;
    }
    .figure-mobile img { max-height: 190mm; object-fit: contain; background: #f8fafc; }
    .capture-missing {
      margin: 4mm 0 6mm;
      padding: 5mm;
      border: 1px dashed #f0a6bd;
      border-radius: 8px;
      background: #fff7fa;
      color: #7f1d1d;
    }
    .capture-missing strong { display: block; margin-bottom: 1mm; }
    .checklist {
      width: 100%;
      border-collapse: collapse;
      margin: 4mm 0 6mm;
      break-inside: avoid;
    }
    .checklist th, .checklist td {
      border: 1px solid #e2e8f0;
      padding: 2.5mm;
      vertical-align: top;
    }
    .checklist th {
      background: #f8fafc;
      color: ${ink};
      text-align: left;
      font-size: 8.6pt;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .pill {
      display: inline-block;
      padding: 1mm 2.5mm;
      border-radius: 999px;
      background: #fce7f0;
      color: ${martinRed};
      font-weight: 800;
      font-size: 8.5pt;
    }
    .footer-note {
      margin-top: 8mm;
      padding-top: 3mm;
      border-top: 1px solid #e2e8f0;
      color: ${muted};
      font-size: 8.8pt;
    }
  </style>
</head>
<body>
  <section class="cover">
    <div>
      <div class="cover-mark" aria-hidden="true"></div>
      <p class="cover-kicker">JP2 Hub - exemple Martin Sols</p>
      <h1>Guide d’utilisation complet du HUB</h1>
      <p class="cover-subtitle">
        Documentation utilisateur avec visuels pour apprendre les gestes essentiels :
        connexion, navigation, reservations, conges, rapports de visite commerciaux,
        comptabilite multi-site, demandes d'acompte, remises de cheques, documents,
        ROMUS et profil.
      </p>
    </div>
    <div class="cover-meta">
      <div class="cover-box"><strong>Version</strong><span>v1.0 utilisateur</span></div>
      <div class="cover-box"><strong>Date</strong><span>${generatedAt}</span></div>
      <div class="cover-box"><strong>Environnement</strong><span>${escapeHtml(baseUrl)}</span></div>
    </div>
  </section>

  <section class="section compact" id="sommaire">
    <h2>Sommaire</h2>
    <nav class="toc">
      <a href="#demarrage"><span>1. Demarrage rapide</span><span>p. 2</span></a>
      <a href="#connexion"><span>2. Connexion et navigation</span><span>p. 3</span></a>
      <a href="#dashboard"><span>3. Tableau de bord</span><span>p. 5</span></a>
      <a href="#reservations"><span>4. Reservations vehicules</span><span>p. 6</span></a>
      <a href="#materiel"><span>5. Location materiel</span><span>p. 8</span></a>
      <a href="#conges"><span>6. Conges</span><span>p. 10</span></a>
      <a href="#rapport-visite"><span>7. Rapport de visite commerciaux</span><span>p. 12</span></a>
      <a href="#comptabilite"><span>8. Controle caisse</span><span>p. 14</span></a>
      <a href="#acomptes"><span>9. Demandes d'acompte</span><span>p. 17</span></a>
      <a href="#cheques"><span>10. Remise de cheques</span><span>p. 19</span></a>
      <a href="#documents"><span>11. Documents</span><span>p. 21</span></a>
      <a href="#romus"><span>12. Tapis ROMUS</span><span>p. 23</span></a>
      <a href="#profil"><span>13. Profil et compte</span><span>p. 24</span></a>
      <a href="#mobile"><span>14. Smartphone, bonnes pratiques et depannage</span><span>p. 25</span></a>
    </nav>

    <div class="module-grid">
      ${moduleCard('Applications HUB', 'Reservations vehicules, location materiel, rapport de visite, conges, documents et tapis ROMUS.')}
      ${moduleCard('Comptabilite', "Controle caisse, demandes d'acompte, remises de cheques et acces Addvance depuis le menu comptable.")}
      ${moduleCard('Administration', 'Menus, droits, sites, modules et utilisateurs selon les permissions attribuees.')}
    </div>
  </section>

  <section class="section" id="demarrage">
    <h2>1. Demarrage rapide</h2>
    <p>
      Le HUB sert a centraliser les operations quotidiennes Martin Sols : savoir ce qui est reserve,
      qui est absent, quelle caisse est controlee, quels cheques sont prets et quels documents sont a disposition.
    </p>
    <div class="intro-grid">
      ${callout('A retenir', 'Commence toujours par verifier le site selectionne en haut de page. Les donnees affichees dependent du site actif.')}
      ${callout('Droits', 'Les menus visibles dependent de ton profil. Si un module manque, il faut demander une verification des droits.')}
    </div>
    <h3>Points forts des modules</h3>
    <p>
      Chaque module a ete pense pour eviter les fichiers disperses, les informations perdues et les controles manuels trop longs.
      Le HUB donne une lecture commune a toute l’equipe, tout en gardant les donnees separees par site.
    </p>
    <div class="module-grid">
      ${moduleCard('Tableau de bord', 'Une vue d’ensemble immediate sur les priorites du jour, les alertes et les raccourcis utiles.')}
      ${moduleCard('Reservations vehicules', 'Des plannings clairs par site pour eviter les doublons et savoir rapidement quel vehicule est disponible.')}
      ${moduleCard('Location materiel', 'Des cartes visuelles pour retrouver une machine, verifier sa disponibilite et reserver sans perdre de temps.')}
      ${moduleCard('Conges', 'Un remplacement du fichier Excel avec les absences visibles par utilisateur, par couleur et par site.')}
      ${moduleCard('Rapport de visite', 'Un planning commercial pour preparer les tournees, suivre les visites clients et garder les comptes rendus au meme endroit.')}
      ${moduleCard('Controle caisse', 'La comptabilite peut passer d’un site a l’autre en un clic et verifier les caisses, ecarts et PDF rapidement.')}
      ${moduleCard("Demandes d'acompte", 'Un suivi centralise des demandes, statuts, montants et justificatifs pour limiter les oublis.')}
      ${moduleCard('Remise de cheques', 'Une remise plus propre : photos, total automatique, controle signature/destinataire et PDF pour la banque.')}
      ${moduleCard('Documents', 'Une bibliotheque commune pour retrouver les promos, fiches techniques et procedures sans chercher dans plusieurs dossiers.')}
      ${moduleCard('Tapis ROMUS', 'Un formulaire integre au HUB pour produire un bon PDF homogene et plus professionnel.')}
    </div>
    <table class="checklist">
      <thead>
        <tr><th>Besoin</th><th>Menu a utiliser</th><th>Resultat attendu</th></tr>
      </thead>
      <tbody>
        <tr><td>Reserver un vehicule</td><td>Applications HUB &gt; Reservations vehicules</td><td>Une reservation visible dans le planning.</td></tr>
        <tr><td>Reserver du materiel</td><td>Applications HUB &gt; Location materiel</td><td>Un creneau matin, apres-midi ou journee selon le materiel.</td></tr>
        <tr><td>Poser ou consulter un conge</td><td>Administration ou Applications &gt; Conges</td><td>Une absence coloree par utilisateur sur le calendrier.</td></tr>
        <tr><td>Preparer une tournee commerciale</td><td>Applications HUB &gt; Rapport de visite</td><td>Un planning de visites clients avec objectifs, comptes rendus et actions a suivre.</td></tr>
        <tr><td>Controler la caisse</td><td>Comptabilite &gt; Controle caisse</td><td>Une caisse du jour avec factures, entrees, sorties et ecarts.</td></tr>
        <tr><td>Suivre une demande d'acompte</td><td>Comptabilite &gt; Demandes d'acompte</td><td>Une demande suivie par statut, demandeur, document et montant.</td></tr>
        <tr><td>Preparer une remise de cheques</td><td>Comptabilite &gt; Remise de cheques</td><td>Une remise avec liste des cheques et total PDF.</td></tr>
        <tr><td>Retrouver un fichier</td><td>Documents &gt; Promo / Fiches techniques / Procedures</td><td>Un document ouvert, telecharge ou depose selon les droits.</td></tr>
      </tbody>
    </table>
  </section>

  <section class="section" id="connexion">
    <h2>2. Connexion et navigation</h2>
    ${figure(cap('login'))}
    <h3>Se connecter</h3>
    ${steps([
      'Ouvre l’adresse du HUB dans le navigateur ou l’application installee.',
      'Saisis ton adresse email et ton mot de passe.',
      'Coche <strong>Rester connecte</strong> uniquement sur un appareil personnel ou professionnel securise.',
      'Clique sur <strong>Se connecter</strong>. Le HUB ouvre ensuite la page d’accueil.'
    ])}
    ${callout('Bon reflexe', 'En cas de page blanche apres un rafraichissement, reviens sur l’adresse principale du HUB puis reconnecte-toi si necessaire.')}

    <h3>Le menu de gauche</h3>
    ${figure(cap('menu-documents'))}
    ${bullets([
      '<strong>Tableau de bord</strong> doit rester le premier lien pour revenir a l’accueil.',
      '<strong>Applications HUB</strong> contient les modules operationnels : reservations, location materiel, rapport de visite, ROMUS.',
      "<strong>Comptabilite</strong> regroupe Controle caisse, Demandes d'acompte, Remise de cheques et Addvance.",
      '<strong>Documents</strong> regroupe Promo, Fiches techniques et Procedures.',
      '<strong>Administration</strong> est reserve aux profils autorises.'
    ])}

    <h3>La barre du haut</h3>
    ${bullets([
      '<strong>Site</strong> : change le site actif, par exemple Palissy ou Pastel.',
      '<strong>Cloche</strong> : affiche les notifications lorsque le HUB en propose.',
      '<strong>Profil</strong> : donne acces aux parametres du compte et a la deconnexion.'
    ])}
  </section>

  <section class="section" id="dashboard">
    <h2>3. Tableau de bord</h2>
    ${figure(cap('dashboard'))}
    <p>
      Le tableau de bord sert de point de depart. Il donne une vue rapide sur les priorites :
      reservations du jour, disponibilites, conges, alertes et raccourcis vers les modules importants.
    </p>
    ${steps([
      'Verifie le site actif en haut de page.',
      'Lis les cartes de synthese pour identifier les actions urgentes.',
      'Utilise les raccourcis pour aller directement vers location materiel, reservations, conges ou caisse.',
      'Reviens ici quand tu changes de mission ou de site.'
    ])}
  </section>

  <section class="section" id="reservations">
    <h2>4. Reservations vehicules</h2>
    ${figure(cap('reservations'))}
    <p>
      Le module reservations vehicules fonctionne avec une logique simple : choisir le vehicule,
      consulter le planning, puis creer ou modifier une reservation selon les droits.
    </p>
    <h3>Creer une reservation</h3>
    ${steps([
      'Ouvre <strong>Reservations vehicules</strong> dans le menu.',
      'Choisis le vehicule concerne.',
      'Selectionne la date dans le planning.',
      'Choisis le creneau disponible puis renseigne les informations demandees.',
      'Valide. La reservation apparait immediatement dans le planning.'
    ])}
    <h3>Lire le planning</h3>
    ${bullets([
      'La vue <span class="pill">Mois</span> sert a voir l’occupation globale.',
      'La vue <span class="pill">Jour</span> sert a reserver rapidement un creneau precis.',
      'Un clic sur une reservation existante ouvre les informations detaillees dans une fenetre compacte.',
      'Si le vehicule est deja reserve, le HUB bloque les creneaux incompatibles.'
    ])}
  </section>

  <section class="section" id="materiel">
    <h2>5. Location materiel</h2>
    ${figure(cap('locations-materiel'))}
    <p>
      La location materiel reprend le meme principe que les reservations vehicules, mais avec des cartes
      visuelles pour identifier rapidement la machine.
    </p>
    <h3>Choisir un materiel</h3>
    ${steps([
      'Ouvre <strong>Location materiel</strong>.',
      'Filtre par categorie si beaucoup de materiel est affiche.',
      'Clique sur la carte de la machine voulue.',
      'Le planning de cette machine s’affiche sous les cartes.',
      'Choisis le jour et le creneau disponible.'
    ])}
    <h3>Creneaux disponibles</h3>
    ${bullets([
      '<strong>Matin</strong> : reservation sur la demi-journee du matin.',
      '<strong>Apres-midi</strong> : reservation sur la demi-journee de l’apres-midi.',
      '<strong>Journee</strong> : reservation de la journee complete.',
      'Certains materiels peuvent etre configures uniquement a la journee.'
    ])}
    ${callout('Lecture rapide', 'Le point de disponibilite sur la carte donne l’etat principal ; les details restent accessibles depuis la popup de reservation.')}
  </section>

  <section class="section" id="conges">
    <h2>6. Conges</h2>
    ${figure(cap('conges'))}
    <p>
      Le module conges remplace le suivi Excel. Il utilise les utilisateurs existants du HUB et les rattachements
      au site selectionne, sans creer des salaries specifiques dans le module.
    </p>
    <h3>Comprendre le calendrier</h3>
    ${bullets([
      'Chaque utilisateur possede une couleur dans la legende.',
      'Les lignes ou points colores representent les absences visibles sur le mois.',
      'Un clic sur une date permet de consulter les absents du jour.',
      'La vue jour sert a lire clairement les absences quand plusieurs salaries sont concernes.'
    ])}
    <h3>Ajouter ou modifier un conge</h3>
    ${steps([
      'Choisis le bon site dans la barre du haut.',
      'Clique sur la date concernee ou sur le bouton d’ajout si ton profil l’autorise.',
      'Selectionne l’utilisateur HUB existant.',
      'Renseigne le type de conge, la date de debut, la date de fin et la demi-journee si necessaire.',
      'Valide. Le HUB signale les conflits si une absence incompatible existe deja.'
    ])}
  </section>

  <section class="section" id="rapport-visite">
    <h2>7. Rapport de visite commerciaux</h2>
    ${figure(cap('rapport-visite'))}
    <p>
      Le module Rapport de visite sert a organiser le travail commercial : preparer les tournees,
      planifier les visites clients, noter les objectifs et conserver un compte rendu exploitable par site.
      Il remplace les notes dispersees et donne une vision claire de ce qui a ete fait et de ce qui reste a suivre.
    </p>
    <h3>Ce que le commercial retrouve</h3>
    ${bullets([
      'Un calendrier mensuel pour visualiser les rapports et les visites prevues.',
      'Des cartes de synthese : rapports du mois, visites realisees, rapports termines et actions a suivre.',
      'Des filtres par mois, representant, statut et recherche client ou ville.',
      'Une fiche detaillee pour chaque rapport avec les visites, objectifs, resultats et prochaines actions.'
    ])}
    <h3>Creer un rapport de visite</h3>
    ${steps([
      'Ouvre <strong>Applications HUB &gt; Rapport de visite</strong>.',
      'Verifie le site actif en haut de page.',
      'Clique sur <strong>Nouveau rapport</strong>.',
      'Choisis la date, le representant, le statut et precise lâ€™objectif de la tournee.',
      'Ajoute les visites clients une par une avec lâ€™heure, le contact, la ville, lâ€™objectif et le resultat.',
      'Complete ensuite la synthese du rapport et les actions a suivre.'
    ])}
    <h3>Bien utiliser le rapport</h3>
    ${bullets([
      'Prepare la tournee avant le depart pour avoir les clients et priorites sous les yeux.',
      'Apres chaque visite, renseigne le resultat pendant que les informations sont fraiches.',
      'Utilise le champ <strong>Prochaine action</strong> pour noter une relance, un devis ou une nouvelle visite.',
      'Passe le rapport en termine quand toutes les visites et actions principales sont saisies.',
      'Filtre par representant pour suivre rapidement lâ€™activite commerciale de chaque personne.'
    ])}
    ${callout('Point fort', 'Le responsable ou la comptabilite peut relire les rapports par site et par representant, sans chercher dans des messages ou fichiers separes.')}
  </section>

  <section class="section" id="comptabilite">
    <h2>8. Controle caisse</h2>
    ${figure(cap('cash-dashboard'))}
    <p>
      Le controle caisse sert a reproduire la logique du fichier Excel avec une interface CRM :
      factures, encaissements, sorties, comptage espece, reports et ecarts.
    </p>
    ${callout('Multi-site', 'Pour la comptabilite, le gros avantage est le controle rapide par site : en changeant le site dans le menu du haut, elle peut verifier Palissy, Pastel ou un autre site en un clic, sans ouvrir plusieurs fichiers.')}
    <h3>Pourquoi c’est utile</h3>
    ${bullets([
      'La comptabilite retrouve les caisses de chaque site depuis le meme endroit.',
      'Les ecarts, reports, encaissements et sorties sont visibles plus vite qu’avec un fichier Excel separe.',
      'Les exports PDF donnent une base propre pour controler, archiver ou transmettre.',
      'Les reperes visuels rouge/vert aident a identifier rapidement ce qui est coherent ou a verifier.'
    ])}
    <h3>Tableau de bord caisse</h3>
    ${bullets([
      'Les cartes du haut donnent les montants clefs avec icones colorees.',
      '<strong>Nouvelle caisse</strong> ouvre une popup pour choisir la date, puis charge la caisse.',
      'Les alertes rouge/vert aident a verifier rapidement les saisies coherentes ou a controler.',
      'Le PDF de caisse reprend les informations importantes, y compris les numeros de facture.'
    ])}
    ${figure(cap('cash-list'))}
    <h3>Liste des caisses</h3>
    ${steps([
      'Ouvre <strong>Comptabilite &gt; Controle caisse &gt; Liste des caisses</strong>.',
      'Filtre par mois et annee.',
      'Utilise les icones en fin de ligne pour voir, modifier ou supprimer une caisse.',
      'Entre dans une caisse pour ajouter les factures, entrees, sorties et pieces justificatives.'
    ])}
    <h3>Saisir une caisse</h3>
    ${bullets([
      '<strong>Ajouter une facture</strong> ouvre une popup de saisie rapide.',
      'Les entrees et sorties doivent etre ajoutees via les boutons dedies pour garder la page lisible.',
      'Le comptage des especes sert a comparer le reel avec le theorique.',
      'Avant validation, verifie les ecarts et les reperes couleur.'
    ])}
  </section>

  <section class="section" id="acomptes">
    <h2>9. Demandes d'acompte</h2>
    ${figure(cap('deposit-requests'))}
    <p>
      Le module demandes d'acompte centralise les demandes du site et leur suivi.
      Il evite les demandes dispersees et permet de voir rapidement ce qui est en attente,
      valide ou refuse selon les droits de l'utilisateur.
    </p>
    ${callout('Point fort', "La comptabilite peut consulter les demandes par site depuis le meme menu et voir rapidement ce qui doit etre valide, complete ou relance.")}
    <h3>Creer une demande</h3>
    ${steps([
      "Ouvre <strong>Comptabilite &gt; Demandes d'acompte</strong>.",
      "Clique sur <strong>Nouvelle demande d'acompte</strong> si ton profil l'autorise.",
      'Renseigne le demandeur, le client ou chantier, le document concerne, le montant et les notes utiles.',
      'Ajoute une piece jointe ou une reference lorsque c’est necessaire pour comprendre la demande.',
      'Valide. La demande apparait dans la liste du site avec son statut.'
    ])}
    <h3>Suivre et traiter</h3>
    ${bullets([
      'Les cartes de synthese indiquent le nombre total de demandes, les demandes en attente et les montants.',
      'La liste permet de retrouver rapidement le demandeur, le document, le montant et la date.',
      'Les icones en fin de ligne servent a consulter, modifier, valider, remettre en attente ou supprimer selon les droits.',
      'Avant validation, controle toujours le site actif et le document associe.'
    ])}
    ${callout('Bon reflexe', "Une demande d'acompte doit rester lisible pour la personne qui la valide : demandeur, montant, chantier/client et justificatif doivent etre explicites.")}
  </section>

  <section class="section" id="cheques">
    <h2>10. Remise de cheques</h2>
    ${figure(cap('check-remittances'))}
    <p>
      Le module remise de cheques sert a preparer une remise bancaire complete : creation de la remise,
      ajout des cheques, controle signature/destinataire, total et impression PDF.
    </p>
    ${callout('Point fort', 'La comptabilite gagne du temps : chaque remise est rattachee au site actif, les montants sont totalises et le PDF peut etre controle sans ressaisie.')}
    <h3>Creer une remise</h3>
    ${steps([
      'Ouvre <strong>Comptabilite &gt; Remise de cheques</strong>.',
      'Clique sur <strong>Nouvelle remise</strong>.',
      'Choisis la date et le site si la popup le demande.',
      'Ouvre la remise creee pour acceder a sa page detaillee.',
      'Ajoute les cheques depuis cette page, un par un.'
    ])}
    <h3>Ajouter un cheque</h3>
    ${bullets([
      'Saisis le numero de facture, le nom et le montant si tu les connais.',
      'Prends une photo du cheque pour conserver le justificatif.',
      'La detection automatique peut aider a remplir le nom et le montant ; corrige manuellement si la lecture est mauvaise.',
      'Controle les cases <strong>Signature</strong> et <strong>Destinataire</strong> avant d’envoyer la remise a la banque.',
      'Clique sur la photo pour l’agrandir et verifier les informations du cheque.'
    ])}
    ${callout('Important', 'L’OCR est une aide. Le controle final du nom, du montant, de la signature et du destinataire reste indispensable.')}
  </section>

  <section class="section" id="documents">
    <h2>11. Documents</h2>
    ${figure(cap('documents-promo'))}
    <p>
      Le menu Documents rassemble les fichiers utiles au quotidien. Les categories prevues sont
      <strong>Promo</strong>, <strong>Fiches techniques</strong> et <strong>Procedures</strong>.
    </p>
    <h3>Consulter un document</h3>
    ${steps([
      'Ouvre le menu <strong>Documents</strong>.',
      'Choisis la categorie : Promo, Fiches techniques ou Procedures.',
      'Utilise la recherche ou le dossier pour retrouver le fichier.',
      'Clique sur le nom ou l’icone oeil pour ouvrir le document.'
    ])}
    <h3>Ajouter ou organiser</h3>
    ${bullets([
      'Les boutons d’ajout sont visibles uniquement avec le droit de gestion.',
      'Un document peut etre rattache a un site et range dans un dossier.',
      'La visibilite permet de distinguer les documents publics et internes.',
      'Evite de multiplier les doublons : remplace le fichier si une version plus recente existe.'
    ])}
  </section>

  <section class="section" id="romus">
    <h2>12. Tapis ROMUS</h2>
    ${figure(cap('tapis-romus'))}
    <p>
      Le module Tapis ROMUS integre l’outil de generation du bon de commande dans le HUB.
      L’objectif est d’obtenir un PDF propre et exploitable sans quitter l’environnement Martin Sols.
    </p>
    ${steps([
      'Ouvre <strong>Applications HUB &gt; Tapis ROMUS</strong>.',
      'Renseigne les informations client, chantier et tapis.',
      'Complete soigneusement les mesures.',
      'Verifie le recapitulatif avant generation.',
      'Genere le PDF final et controle sa lisibilite avant envoi.'
    ])}
  </section>

  <section class="section" id="profil">
    <h2>13. Profil et compte</h2>
    ${figure(cap('account-settings'))}
    <p>
      La page profil garde les informations du compte coherentes avec le menu du haut : nom, email,
      photo de profil et informations personnelles selon les droits disponibles.
    </p>
    ${bullets([
      'Ouvre le profil depuis l’avatar en haut a droite ou depuis <strong>/pages/account-settings</strong>.',
      'Modifie uniquement les informations exactes et professionnelles.',
      'Apres modification de l’email ou du mot de passe, reconnecte-toi si le HUB le demande.',
      'Signale tout probleme de droit ou de site a un administrateur.'
    ])}
  </section>

  <section class="section" id="mobile">
    <h2>14. Smartphone et tablette</h2>
    <div class="mobile-pair">
      ${figure(mobile('mobile-dashboard'), 'mobile')}
      ${figure(mobile('mobile-location'), 'mobile')}
    </div>
    <p>
      Le HUB est responsive : les memes modules sont disponibles sur ordinateur, tablette et smartphone.
      Sur petit ecran, le menu passe par le bouton hamburger pour laisser la place au contenu.
    </p>
    ${bullets([
      'Utilise le selecteur de site en haut avant de saisir une operation.',
      'Fais defiler la page pour atteindre les cartes ou le planning.',
      'Les popups sont condensees pour permettre une saisie rapide.',
      'Pour les photos de cheque, place le cheque a plat, bien eclaire, avec tout le montant visible.'
    ])}
  </section>

  <section class="section" id="bonnes-pratiques">
    <h2>Bonnes pratiques et depannage</h2>
    <div class="faq-grid">
      ${moduleCard('Je ne vois pas un module', 'Verifie le site actif puis demande a un administrateur de controler tes droits par site et module.')}
      ${moduleCard('Une page semble blanche', 'Recharge depuis l’adresse principale du HUB. Si le souci persiste, deconnecte-toi puis reconnecte-toi.')}
      ${moduleCard("Une demande d'acompte manque", "Verifie le site actif, le filtre de statut et les droits du module Demandes d'acompte.")}
      ${moduleCard('Une reservation ne passe pas', 'Controle la date, le creneau et les conflits existants dans le planning.')}
      ${moduleCard('L’OCR cheque se trompe', 'Corrige le nom et le montant manuellement. La photo sert d’aide, pas de validation automatique definitive.')}
      ${moduleCard('La caisse presente un ecart', 'Controle les factures, especes, CB, virements, remises banque, entrees et sorties avant export PDF.')}
      ${moduleCard('Un document est introuvable', 'Change de categorie, vide la recherche, verifie le dossier et le site actif.')}
    </div>
    <h3>Regles simples</h3>
    ${bullets([
      'Toujours verifier le site actif avant de creer une donnee.',
      'Toujours relire les popups avant validation.',
      'Ne supprimer une donnee que si tu es certain qu’elle est fausse ou obsolete.',
      'Utiliser les exports PDF pour les controles et transmissions.',
      'Signaler rapidement les erreurs de droit, menu ou affichage.'
    ])}
    <p class="footer-note">
      Guide HUB Martin Sols. Les captures refletent l’interface accessible au compte utilise pendant la generation.<br>
      Création visuelle et conception CRM : www.jp2creation.fr
    </p>
  </section>
</body>
</html>`;
}

async function main() {
  await ensureDirs();

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: desktopViewport,
    deviceScaleFactor: 1,
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
  });
  const page = await context.newPage();

  const loginCapture = await capturePage(page, captures[0]);

  await login(page);

  const protectedCaptures = [];
  for (const capture of captures.slice(1)) {
    protectedCaptures.push(await capturePage(page, capture));
  }

  const storageState = await context.storageState();
  const mobileContext = await browser.newContext({
    storageState,
    viewport: mobileViewport,
    deviceScaleFactor: 1,
    isMobile: true,
    hasTouch: true,
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
  });
  const mobilePage = await mobileContext.newPage();
  const protectedMobileCaptures = [];
  for (const capture of mobileCaptures) {
    protectedMobileCaptures.push(await capturePage(mobilePage, capture, 'mobile'));
  }

  const allCaptures = [loginCapture, ...protectedCaptures];
  const html = renderHtml(allCaptures, protectedMobileCaptures);
  await fs.writeFile(htmlPath, html, 'utf8');
  console.log(`HTML genere: ${htmlPath}`);

  const pdfContext = await browser.newContext({
    viewport: { width: 1200, height: 1600 },
    deviceScaleFactor: 1,
    locale: 'fr-FR',
  });
  const pdfPage = await pdfContext.newPage();
  await pdfPage.goto(pathToFileURL(htmlPath).href, { waitUntil: 'networkidle' });
  await pdfPage.pdf({
    path: pdfPath,
    format: 'A4',
    printBackground: true,
    margin: { top: '12mm', right: '10mm', bottom: '12mm', left: '10mm' },
  });

  await browser.close();
  console.log(`PDF genere: ${pdfPath}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
