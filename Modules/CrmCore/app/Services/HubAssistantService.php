<?php

namespace Modules\CrmCore\Services;

use App\Models\CrmMenuItem;
use App\Models\CrmModule;
use App\Models\CrmPage;
use App\Models\CrmUser;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HubAssistantService
{
    public function __construct(private readonly CrmAccessService $access) {}

    public function actorForUser(User $user): CrmUser
    {
        $actor = CrmUser::query()
            ->with(['modules:id,slug,active', 'permissions:id,name,label,sort_order', 'sites:id,active'])
            ->where('user_id', $user->id)
            ->where('active', true)
            ->first();

        if (! $actor) {
            throw new NotFoundHttpException('Utilisateur HUB introuvable');
        }

        return $actor;
    }

    /**
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }
     */
    public function reply(CrmUser $actor, string $message): array
    {
        $destinations = $this->destinations($actor);

        if ($destinations === []) {
            return [
                'ok' => true,
                'message' => 'Je ne trouve aucune page accessible pour votre compte.',
                'url' => null,
                'label' => null,
                'suggestions' => [],
            ];
        }

        $ranked = $this->rankDestinations($message, $destinations);

        if ($ranked === []) {
            return [
                'ok' => true,
                'message' => 'Je ne trouve pas de page évidente. Voici les raccourcis les plus utiles.',
                'url' => null,
                'label' => null,
                'suggestions' => $this->suggestions($destinations, 4),
            ];
        }

        $best = $ranked[0]['destination'];
        $confidence = $ranked[0]['score'];

        if ($confidence < 7) {
            return [
                'ok' => true,
                'message' => 'Je ne suis pas sûr de la meilleure page. Voici les raccourcis les plus proches.',
                'url' => null,
                'label' => null,
                'suggestions' => $this->suggestions(array_column($ranked, 'destination'), 4),
            ];
        }

        return [
            'ok' => true,
            'message' => 'J’ai trouvé la page '.$best['label'].'.',
            'url' => $best['url'],
            'label' => 'Ouvrir '.$best['label'],
            'suggestions' => $this->suggestions(array_column(array_slice($ranked, 1), 'destination'), 3),
        ];
    }

    /**
     * @return array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>
     */
    private function destinations(CrmUser $actor): array
    {
        $moduleIds = $this->access->moduleIds($actor);
        $destinations = [
            [
                'label' => 'Paramètres du compte',
                'url' => '/pages/account-settings',
                'keywords' => ['compte', 'profil', 'photo', 'email', 'e-mail', 'telephone', 'mot de passe', 'preferences'],
                'external' => false,
            ],
        ];

        if ($moduleIds !== []) {
            $modules = CrmModule::query()
                ->active()
                ->whereIn('id', $moduleIds)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            $menuItems = CrmMenuItem::query()
                ->where('active', true)
                ->whereIn('item_key', $modules->map(fn (CrmModule $module): string => 'module:'.$module->slug)->all())
                ->get()
                ->keyBy('item_key');

            foreach ($modules as $module) {
                $url = (string) ($module->route_path ?: '/'.$module->slug);

                if (blank($url) || $url === '#') {
                    continue;
                }

                $slug = (string) $module->slug;
                $menuItem = $menuItems->get('module:'.$slug);
                $label = $menuItem?->label ?: $module->name;

                $destinations[] = [
                    'label' => $label,
                    'url' => $url,
                    'keywords' => $this->keywordsForModule($slug, $module->name, $module->description, $label),
                    'external' => Str::startsWith($url, ['http://', 'https://']),
                ];
            }
        }

        if ($this->access->hasModule($actor, 'pages-crm')) {
            foreach ($this->pages() as $page) {
                $destinations[] = $page;
            }
        }

        return $destinations;
    }

    /**
     * @return array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>
     */
    private function pages(): array
    {
        return CrmPage::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get(['title', 'slug', 'excerpt'])
            ->map(function (CrmPage $page): array {
                return [
                    'label' => $page->title,
                    'url' => $page->route_path,
                    'keywords' => array_filter([
                        'page interne',
                        'page hub',
                        $page->title,
                        $page->slug,
                        $page->excerpt,
                    ]),
                    'external' => false,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array<int, array{score: int, destination: array{label: string, url: string, keywords: array<int, string>, external: bool}}>
     */
    private function rankDestinations(string $message, array $destinations): array
    {
        $query = $this->normalize($message);
        $tokens = $this->tokens($query);
        $ranked = [];

        if ($query === '' || $tokens === []) {
            return [];
        }

        foreach ($destinations as $destination) {
            $haystack = $this->normalize(implode(' ', [
                $destination['label'],
                $destination['url'],
                ...$destination['keywords'],
            ]));
            $haystackTokens = array_flip($this->tokens($haystack, removeStopWords: false));
            $score = $this->score($query, $tokens, $haystack, $haystackTokens);

            if ($score > 0) {
                $ranked[] = [
                    'score' => $score,
                    'destination' => $destination,
                ];
            }
        }

        usort($ranked, function (array $first, array $second): int {
            $scoreComparison = $second['score'] <=> $first['score'];

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return $first['destination']['label'] <=> $second['destination']['label'];
        });

        return $ranked;
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, int>  $haystackTokens
     */
    private function score(string $query, array $tokens, string $haystack, array $haystackTokens): int
    {
        $score = str_contains($haystack, $query) ? 12 : 0;

        foreach ($tokens as $token) {
            if (isset($haystackTokens[$token])) {
                $score += 8;
            } elseif (str_contains($haystack, $token)) {
                $score += 3;
            }
        }

        return $score;
    }

    /**
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array<int, array{label: string, url: string, external: bool}>
     */
    private function suggestions(array $destinations, int $limit): array
    {
        return collect($destinations)
            ->take($limit)
            ->map(fn (array $destination): array => [
                'label' => $destination['label'],
                'url' => $destination['url'],
                'external' => $destination['external'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function keywordsForModule(string $slug, string $name, ?string $description, string $label): array
    {
        $keywords = [
            'dashboard' => ['accueil', 'tableau de bord', 'synthese', 'statistiques', 'jour'],
            'reservations' => ['reservations', 'vehicules', 'camion', 'sprinter', 'planning', 'voiture'],
            'locations-materiel' => ['location', 'materiel', 'outil', 'outillage', 'machine', 'stock'],
            'equipes' => ['equipe', 'membres', 'utilisateurs', 'annuaire', 'site', 'telephone', 'adresse'],
            'conges' => ['conges', 'absences', 'vacances', 'soldes', 'demandes', 'calendrier', 'equipe'],
            'pilotage-commercial' => ['pilotage', 'commercial', 'ventes', 'clients', 'devis', 'chiffre affaire'],
            'tournees-representants' => ['rapport', 'visite', 'tournees', 'representant', 'commercial'],
            'documents-promo' => ['documents', 'promo', 'publicite', 'catalogue'],
            'documents-fiches-techniques' => ['documents', 'fiches techniques', 'produits'],
            'documents-procedures' => ['documents', 'procedures', 'consignes', 'process'],
            'pages-crm' => ['pages', 'hub', 'pages internes', 'contenu'],
            'controle-caisse' => ['controle', 'caisse', 'ticket', 'erreurs', 'ecarts', 'comptabilite'],
            'demandes-acompte' => ['demande', 'acompte', 'client', 'paiement', 'facture'],
            'remise-cheques' => ['remise', 'cheques', 'banque', 'depot', 'comptabilite'],
            'administration' => ['administration', 'admin', 'reglages', 'utilisateurs', 'sites', 'modules', 'menus', 'permissions'],
            'addvance' => ['addvance', 'comptabilite', 'factures', 'fournisseurs'],
            'tapis-romus' => ['tapis', 'romus', 'mesure', 'plan', 'coupe'],
        ][$slug] ?? [];

        return array_values(array_filter(array_unique([
            $slug,
            $name,
            $description,
            $label,
            ...$keywords,
        ])));
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $value, bool $removeStopWords = true): array
    {
        $tokens = preg_split('/\s+/', $value) ?: [];

        return collect($tokens)
            ->filter(fn (string $token): bool => strlen($token) > 1)
            ->reject(fn (string $token): bool => $removeStopWords && in_array($token, $this->stopWords(), true))
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';

        return trim(preg_replace('/\s+/', ' ', $value) ?: '');
    }

    /**
     * @return array<int, string>
     */
    private function stopWords(): array
    {
        return [
            'a',
            'au',
            'aux',
            'avec',
            'dans',
            'de',
            'des',
            'du',
            'en',
            'est',
            'et',
            'je',
            'la',
            'le',
            'les',
            'me',
            'mes',
            'mon',
            'nous',
            'ouvrir',
            'page',
            'pour',
            'que',
            'qui',
            'recherche',
            'sur',
            'tu',
            'un',
            'une',
            'veux',
            'voir',
        ];
    }
}
