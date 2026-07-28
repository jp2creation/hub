<?php

namespace Modules\CrmCore\Services;

use App\Models\CachedBillingStat;
use App\Models\CrmCashRegisterDay;
use App\Models\CrmMenuItem;
use App\Models\CrmModule;
use App\Models\CrmPage;
use App\Models\CrmReservation;
use App\Models\CrmSalesInvoice;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmVehicle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
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
    public function reply(CrmUser $actor, string $message, ?int $requestedSiteId = null): array
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

        $directReply = $this->directReply($actor, $message, $destinations, $requestedSiteId);

        if ($directReply !== null) {
            return $directReply;
        }

        $guidedReply = $this->guidedReply($message, $destinations);

        if ($guidedReply !== null) {
            return $guidedReply;
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
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }|null
     */
    private function directReply(CrmUser $actor, string $message, array $destinations, ?int $requestedSiteId = null): ?array
    {
        $query = $this->normalize($message);
        $field = $this->requestedInformationField($query);

        if ($query === '') {
            return null;
        }

        if ($this->isRevenueQuestion($query)) {
            return $this->revenueReply($actor, $query, $destinations, $requestedSiteId);
        }

        if ($this->isVehicleAvailabilityQuestion($query)) {
            return $this->vehicleAvailabilityReply($actor, $query, $destinations, $requestedSiteId);
        }

        if ($field === null) {
            return null;
        }

        if (in_array($field, ['phone', 'email', 'sites', 'role'], true)) {
            $memberReply = $this->directMemberReply($actor, $query, $field, $destinations);

            if ($memberReply !== null) {
                return $memberReply;
            }
        }

        if (in_array($field, ['phone', 'email', 'address', 'hours'], true)) {
            return $this->directSiteReply($actor, $query, $field, $destinations);
        }

        return null;
    }

    /**
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }|null
     */
    private function directMemberReply(CrmUser $actor, string $query, string $field, array $destinations): ?array
    {
        if ($this->isSelfInformationQuestion($query)) {
            return $this->memberInformationReply($actor, $field);
        }

        $subjectTokens = $this->informationSubjectTokens($query);

        if ($subjectTokens === []) {
            return null;
        }

        if (! $this->access->hasModule($actor, 'equipes')) {
            return $this->teamInformationUnavailableReply($destinations);
        }

        $ranked = $this->rankMembers($query, $subjectTokens, $this->visibleMembers($actor));

        if ($ranked === []) {
            return null;
        }

        if ($this->hasAmbiguousTopMatch($ranked)) {
            return $this->ambiguousMembersReply($ranked);
        }

        return $this->memberInformationReply($ranked[0]['member'], $field);
    }

    /**
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }|null
     */
    private function directSiteReply(CrmUser $actor, string $query, string $field, array $destinations): ?array
    {
        $subjectTokens = $this->informationSubjectTokens($query);
        $mentionsSite = $this->matchesAny($query, ['site', 'sites', 'agence', 'agences', 'magasin', 'magasins']);

        if ($subjectTokens === [] && ! $mentionsSite) {
            return null;
        }

        if (! $this->access->hasModule($actor, 'equipes')) {
            return $this->teamInformationUnavailableReply($destinations);
        }

        $sites = $this->visibleSites($actor);

        if ($subjectTokens === []) {
            if (count($sites) === 1) {
                return $this->siteInformationReply($sites[0], $field);
            }

            return [
                'ok' => true,
                'message' => 'Je peux répondre, mais j’ai besoin du nom du site.',
                'url' => null,
                'label' => null,
                'suggestions' => [],
            ];
        }

        $ranked = $this->rankSites($query, $subjectTokens, $sites);

        if ($ranked === []) {
            return null;
        }

        if ($this->hasAmbiguousTopMatch($ranked)) {
            return $this->ambiguousSitesReply($ranked);
        }

        return $this->siteInformationReply($ranked[0]['site'], $field);
    }

    private function isRevenueQuestion(string $query): bool
    {
        return $this->matchesAny($query, [
            'ca du jour',
            'ca du mois',
            'ca hier',
            'chiffre affaire',
            'chiffre d affaire',
            'chiffre hier',
            'facturation',
            'mes ventes',
            'mon ca',
            'mon chiffre',
        ]) || (
            $this->hasAnyToken($query, ['ca', 'chiffre', 'ventes'])
            && $this->hasAnyToken($query, ['caisse', 'commercial', 'facture', 'hier', 'jour', 'mois', 'vente'])
        );
    }

    private function isVehicleAvailabilityQuestion(string $query): bool
    {
        return $this->matchesAny($query, ['camion', 'creneau', 'creneaux', 'dispo', 'disponible', 'planning', 'reserve', 'reservation', 'sprinter', 'vehicule'])
            && $this->matchesAny($query, ['creneau', 'creneaux', 'dispo', 'disponible', 'libre', 'planning', 'reserve', 'reservation']);
    }

    /**
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }
     */
    private function revenueReply(CrmUser $actor, string $query, array $destinations, ?int $requestedSiteId): array
    {
        $period = $this->businessPeriod($query);

        $salesScope = $this->siteScopeForModuleQuestion($actor, $query, $requestedSiteId, 'pilotage-commercial', [
            'sales.view',
            'sales.sync',
            'sales.manage',
            'sales.commissions',
        ]);

        if ($salesScope['siteIds'] !== []) {
            return $this->salesRevenueReply($actor, $query, $period, $salesScope, $destinations);
        }

        $statsScope = $this->siteScopeForModuleQuestion($actor, $query, $requestedSiteId, 'stats', [
            'stats.view',
            'stats.sync',
            'stats.manage',
        ]);

        if ($statsScope['siteIds'] !== []) {
            return $this->billingStatsRevenueReply($period, $statsScope, $destinations);
        }

        $cashScope = $this->siteScopeForModuleQuestion($actor, $query, $requestedSiteId, 'controle-caisse', [
            'controle_caisse.view',
            'controle_caisse.manage',
        ]);

        if ($cashScope['siteIds'] !== []) {
            return $this->cashRevenueReply($period, $cashScope, $destinations);
        }

        return $this->businessUnavailableReply(
            'Je comprends la demande de chiffre, mais je ne vois ni Pilotage commercial, ni Stats, ni Contrôle caisse dans vos accès actuels.',
            $destinations,
        );
    }

    /**
     * @param  array{label:string,start:CarbonImmutable,end:CarbonImmutable}  $period
     * @param  array{siteIds:array<int, int>, label:string}  $scope
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }
     */
    private function salesRevenueReply(CrmUser $actor, string $query, array $period, array $scope, array $destinations): array
    {
        $invoiceQuery = CrmSalesInvoice::query()
            ->whereIn('site_id', $scope['siteIds'])
            ->whereBetween('issue_date', [$period['start']->toDateTimeString(), $period['end']->toDateTimeString()])
            ->where('status', CrmSalesInvoice::STATUS_PAID)
            ->when($this->isPersonalRevenueQuestion($query), fn ($query) => $query->where('representative_user_id', $actor->id));

        $total = round((float) (clone $invoiceQuery)->sum('total'), 2);
        $invoiceCount = (int) (clone $invoiceQuery)->count();
        $destination = $this->firstDestinationMatching($destinations, ['pilotage', 'commercial', 'ventes']);
        $subject = $this->isPersonalRevenueQuestion($query) ? 'Votre chiffre commercial' : 'Le chiffre commercial';

        return [
            'ok' => true,
            'message' => $subject.' '.$period['label'].' sur '.$scope['label'].' est de '.$this->money($total).' ('.$invoiceCount.' facture(s) payée(s)).',
            'url' => $destination['url'] ?? '/pilotage-commercial',
            'label' => 'Ouvrir '.($destination['label'] ?? 'Pilotage commercial'),
            'suggestions' => $this->suggestionsWithoutUrl($destinations, $destination['url'] ?? '/pilotage-commercial', 3),
        ];
    }

    /**
     * @param  array{label:string,start:CarbonImmutable,end:CarbonImmutable}  $period
     * @param  array{siteIds:array<int, int>, label:string}  $scope
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }
     */
    private function billingStatsRevenueReply(array $period, array $scope, array $destinations): array
    {
        $statsQuery = CachedBillingStat::query()
            ->whereIn('site_id', $scope['siteIds'])
            ->whereBetween('date', [$period['start']->toDateTimeString(), $period['end']->toDateTimeString()]);

        $total = round((float) (clone $statsQuery)->sum('total_amount'), 2);
        $invoiceCount = (int) (clone $statsQuery)->sum('invoice_count');
        $destination = $this->firstDestinationMatching($destinations, ['stats', 'statistiques', 'admin stats']);

        return [
            'ok' => true,
            'message' => 'D’après les Stats, le chiffre '.$period['label'].' sur '.$scope['label'].' est de '.$this->money($total).' ('.$invoiceCount.' facture(s)).',
            'url' => $destination['url'] ?? '/admin/stats',
            'label' => 'Ouvrir '.($destination['label'] ?? 'Stats'),
            'suggestions' => $this->suggestionsWithoutUrl($destinations, $destination['url'] ?? '/admin/stats', 3),
        ];
    }

    /**
     * @param  array{label:string,start:CarbonImmutable,end:CarbonImmutable}  $period
     * @param  array{siteIds:array<int, int>, label:string}  $scope
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }
     */
    private function cashRevenueReply(array $period, array $scope, array $destinations): array
    {
        $cashQuery = CrmCashRegisterDay::query()
            ->whereIn('site_id', $scope['siteIds'])
            ->whereBetween('cash_date', [$period['start']->toDateTimeString(), $period['end']->toDateTimeString()]);

        $total = round((float) (clone $cashQuery)->sum('invoice_total'), 2);
        $dayCount = (int) (clone $cashQuery)->count();
        $destination = $this->firstDestinationMatching($destinations, ['controle caisse', 'caisse']);

        return [
            'ok' => true,
            'message' => 'D’après le contrôle caisse, le chiffre '.$period['label'].' sur '.$scope['label'].' est de '.$this->money($total).' ('.$dayCount.' journée(s) de caisse).',
            'url' => $destination['url'] ?? '/controle-caisse',
            'label' => 'Ouvrir '.($destination['label'] ?? 'Contrôle caisse'),
            'suggestions' => $this->suggestionsWithoutUrl($destinations, $destination['url'] ?? '/controle-caisse', 3),
        ];
    }

    private function isPersonalRevenueQuestion(string $query): bool
    {
        return $this->matchesAny($query, ['mon chiffre', 'mes ventes', 'mon ca', 'pour moi']);
    }

    /**
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }
     */
    private function vehicleAvailabilityReply(CrmUser $actor, string $query, array $destinations, ?int $requestedSiteId): array
    {
        $scope = $this->siteScopeForModuleQuestion($actor, $query, $requestedSiteId, 'reservations', [
            'reservations.view',
            'reservations.create',
            'reservations.manage_vehicles',
        ]);

        if ($scope['siteIds'] === []) {
            return $this->businessUnavailableReply(
                'Je comprends la demande véhicule, mais je ne vois pas le module Réservations véhicules dans vos accès actuels.',
                $destinations,
            );
        }

        $vehicles = CrmVehicle::query()
            ->with('site:id,name,slug')
            ->active()
            ->whereIn('site_id', $scope['siteIds'])
            ->orderBy('name')
            ->get()
            ->all();

        if ($vehicles === []) {
            return $this->businessUnavailableReply('Aucun véhicule actif n’est visible sur '.$scope['label'].'.', $destinations);
        }

        $rankedVehicles = $this->rankVehicles($query, $this->businessSubjectTokens($query), $vehicles);

        if ($rankedVehicles === [] && count($vehicles) > 1) {
            return [
                'ok' => true,
                'message' => 'Je peux vérifier un planning véhicule, mais j’ai besoin du nom du véhicule. Véhicules visibles : '.collect($vehicles)->take(5)->pluck('name')->implode(', ').'.',
                'url' => null,
                'label' => null,
                'suggestions' => $this->suggestions($destinations, 4),
            ];
        }

        if ($rankedVehicles !== [] && $this->hasAmbiguousTopMatch($rankedVehicles)) {
            return $this->ambiguousVehiclesReply($rankedVehicles);
        }

        $vehicle = $rankedVehicles[0]['vehicle'] ?? $vehicles[0];
        $day = $this->businessDay($query);
        $reservations = $this->vehicleReservationsForDay($vehicle, $day);
        $busyRanges = $this->busyRanges($vehicle, $reservations, $day);
        $freeRanges = $this->freeRanges($vehicle, $busyRanges);
        $destination = $this->firstDestinationMatching($destinations, ['reservation', 'vehicule', 'sprinter']);
        $dateLabel = $day->isToday() ? 'aujourd’hui' : 'le '.$day->format('d/m/Y');
        $siteName = $vehicle->site?->name ?: $scope['label'];
        $busyLabel = $busyRanges === [] ? 'aucune réservation' : $this->rangesLabel($busyRanges);
        $freeLabel = $freeRanges === [] ? 'aucun créneau libre' : $this->rangesLabel($freeRanges);
        $status = $busyRanges === [] ? 'n’a aucune réservation' : 'est réservé';

        return [
            'ok' => true,
            'message' => $vehicle->name.' ('.$siteName.') '.$status.' '.$dateLabel.'. Réservations : '.$busyLabel.'. Créneaux libres : '.$freeLabel.'.',
            'url' => $destination['url'] ?? '/reservations',
            'label' => 'Ouvrir '.($destination['label'] ?? 'Réservations véhicules'),
            'suggestions' => $this->suggestionsWithoutUrl($destinations, $destination['url'] ?? '/reservations', 3),
        ];
    }

    /**
     * @param  array<int, string>  $subjectTokens
     * @param  array<int, CrmVehicle>  $vehicles
     * @return array<int, array{score: int, vehicle: CrmVehicle}>
     */
    private function rankVehicles(string $query, array $subjectTokens, array $vehicles): array
    {
        if ($subjectTokens === []) {
            return [];
        }

        $subjectQuery = implode(' ', $subjectTokens);
        $ranked = [];

        foreach ($vehicles as $vehicle) {
            $haystack = $this->normalize(implode(' ', array_filter([
                $vehicle->name,
                $vehicle->description,
                $vehicle->site?->name,
                $vehicle->site?->slug,
            ])));
            $score = $this->score($subjectQuery, $subjectTokens, $haystack, array_flip($this->tokens($haystack, removeStopWords: false)));

            if ($score > 0) {
                $ranked[] = [
                    'score' => $score + ($this->matchesAny($query, [$vehicle->name]) ? 8 : 0),
                    'vehicle' => $vehicle,
                ];
            }
        }

        usort($ranked, fn (array $first, array $second): int => $second['score'] <=> $first['score']);

        return $ranked;
    }

    /**
     * @param  array<int, array{score: int, vehicle: CrmVehicle}>  $ranked
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }
     */
    private function ambiguousVehiclesReply(array $ranked): array
    {
        $topScore = $ranked[0]['score'];
        $names = collect($ranked)
            ->filter(fn (array $match): bool => $match['score'] === $topScore)
            ->take(4)
            ->map(fn (array $match): string => $match['vehicle']->name)
            ->implode(', ');

        return [
            'ok' => true,
            'message' => 'J’ai trouvé plusieurs véhicules possibles : '.$names.'. Précisez le nom du véhicule.',
            'url' => null,
            'label' => null,
            'suggestions' => [],
        ];
    }

    /**
     * @return array<int, CrmReservation>
     */
    private function vehicleReservationsForDay(CrmVehicle $vehicle, CarbonImmutable $day): array
    {
        return CrmReservation::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('start_at', '<', $day->endOfDay())
            ->where('end_at', '>', $day->startOfDay())
            ->orderBy('start_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @param  array<int, CrmReservation>  $reservations
     * @return array<int, array{start:int,end:int}>
     */
    private function busyRanges(CrmVehicle $vehicle, array $reservations, CarbonImmutable $day): array
    {
        $hours = $vehicle->dailyReservationHours($vehicle->site);
        $dayStart = $this->minutesFromTime($hours['start']);
        $dayEnd = $this->minutesFromTime($hours['end']);
        $ranges = [];

        foreach ($reservations as $reservation) {
            if (! $reservation->start_at || ! $reservation->end_at) {
                continue;
            }

            $start = max($dayStart, $this->minutesFromDateTime(CarbonImmutable::instance($reservation->start_at)));
            $end = min($dayEnd, $this->minutesFromDateTime(CarbonImmutable::instance($reservation->end_at)));

            if ($reservation->start_at->lt($day->startOfDay()) && $reservation->end_at->gt($day->startOfDay())) {
                $start = $dayStart;
            }

            if ($reservation->end_at->gt($day->endOfDay())) {
                $end = $dayEnd;
            }

            if ($end > $start) {
                $ranges[] = ['start' => $start, 'end' => $end];
            }
        }

        usort($ranges, fn (array $first, array $second): int => $first['start'] <=> $second['start']);

        return $this->mergeRanges($ranges);
    }

    /**
     * @param  array<int, array{start:int,end:int}>  $busyRanges
     * @return array<int, array{start:int,end:int}>
     */
    private function freeRanges(CrmVehicle $vehicle, array $busyRanges): array
    {
        $hours = $vehicle->dailyReservationHours($vehicle->site);
        $cursor = $this->minutesFromTime($hours['start']);
        $dayEnd = $this->minutesFromTime($hours['end']);
        $freeRanges = [];

        foreach ($busyRanges as $range) {
            if ($range['start'] > $cursor) {
                $freeRanges[] = ['start' => $cursor, 'end' => $range['start']];
            }

            $cursor = max($cursor, $range['end']);
        }

        if ($cursor < $dayEnd) {
            $freeRanges[] = ['start' => $cursor, 'end' => $dayEnd];
        }

        return $freeRanges;
    }

    /**
     * @param  array<int, array{start:int,end:int}>  $ranges
     * @return array<int, array{start:int,end:int}>
     */
    private function mergeRanges(array $ranges): array
    {
        $merged = [];

        foreach ($ranges as $range) {
            $lastIndex = count($merged) - 1;

            if ($lastIndex >= 0 && $range['start'] <= $merged[$lastIndex]['end']) {
                $merged[$lastIndex]['end'] = max($merged[$lastIndex]['end'], $range['end']);

                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }

    /**
     * @param  array<int, array{start:int,end:int}>  $ranges
     */
    private function rangesLabel(array $ranges): string
    {
        return collect($ranges)
            ->map(fn (array $range): string => $this->timeLabel($range['start']).'-'.$this->timeLabel($range['end']))
            ->implode(', ');
    }

    /**
     * @return array{label:string,start:CarbonImmutable,end:CarbonImmutable}
     */
    private function businessPeriod(string $query): array
    {
        $today = CarbonImmutable::today($this->displayTimezone());

        if ($this->matchesAny($query, ['avant hier'])) {
            $day = $today->subDays(2);

            return ['label' => 'avant-hier', 'start' => $day->startOfDay(), 'end' => $day->endOfDay()];
        }

        if ($this->matchesAny($query, ['hier'])) {
            $day = $today->subDay();

            return ['label' => 'hier', 'start' => $day->startOfDay(), 'end' => $day->endOfDay()];
        }

        if ($this->matchesAny($query, ['aujourd hui', 'ce jour', 'du jour'])) {
            return ['label' => 'aujourd’hui', 'start' => $today->startOfDay(), 'end' => $today->endOfDay()];
        }

        if ($this->matchesAny($query, ['semaine'])) {
            return ['label' => 'cette semaine', 'start' => $today->startOfWeek(), 'end' => $today->endOfWeek()];
        }

        if ($this->matchesAny($query, ['mois dernier', 'mois precedent'])) {
            $month = $today->subMonthNoOverflow();

            return ['label' => 'le mois dernier', 'start' => $month->startOfMonth(), 'end' => $month->endOfMonth()];
        }

        return ['label' => 'ce mois', 'start' => $today->startOfMonth(), 'end' => $today->endOfMonth()];
    }

    private function businessDay(string $query): CarbonImmutable
    {
        $today = CarbonImmutable::today($this->displayTimezone());

        if ($this->matchesAny($query, ['demain'])) {
            return $today->addDay();
        }

        if ($this->matchesAny($query, ['avant hier'])) {
            return $today->subDays(2);
        }

        if ($this->matchesAny($query, ['hier'])) {
            return $today->subDay();
        }

        return $today;
    }

    /**
     * @param  array<int, string>  $permissionNames
     * @return array{siteIds:array<int, int>, label:string}
     */
    private function siteScopeForModuleQuestion(CrmUser $actor, string $query, ?int $requestedSiteId, string $moduleSlug, array $permissionNames): array
    {
        $siteIds = $this->access->siteIdsForModule($actor, $moduleSlug, $permissionNames);

        if ($siteIds === []) {
            return ['siteIds' => [], 'label' => ''];
        }

        $sites = $this->sitesForIds($siteIds);

        if ($this->matchesAny($query, ['tous les sites', 'toutes les agences', 'global', 'tout le hub'])) {
            return ['siteIds' => $siteIds, 'label' => 'tous les sites autorisés'];
        }

        $subjectTokens = $this->businessSubjectTokens($query);
        $rankedSites = $subjectTokens === [] ? [] : $this->rankSites($query, $subjectTokens, $sites);

        if ($rankedSites !== []) {
            $site = $rankedSites[0]['site'];

            return ['siteIds' => [(int) $site->id], 'label' => $site->name];
        }

        if ($requestedSiteId && in_array($requestedSiteId, $siteIds, true)) {
            $site = collect($sites)->first(fn (CrmSite $site): bool => (int) $site->id === $requestedSiteId);

            if ($site) {
                return ['siteIds' => [$requestedSiteId], 'label' => $site->name];
            }
        }

        $defaultSiteId = $this->defaultSiteId($actor, $siteIds);
        $selectedSiteId = $defaultSiteId ?: $siteIds[0];
        $site = collect($sites)->first(fn (CrmSite $site): bool => (int) $site->id === $selectedSiteId);

        return [
            'siteIds' => [$selectedSiteId],
            'label' => $site?->name ?: 'le site autorisé',
        ];
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, CrmSite>
     */
    private function sitesForIds(array $siteIds): array
    {
        return CrmSite::query()
            ->active()
            ->whereIn('id', $siteIds)
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    private function defaultSiteId(CrmUser $actor, array $siteIds): ?int
    {
        $actor->loadMissing('sites:id,name,active');
        $defaultSite = $actor->sites
            ->first(fn (CrmSite $site): bool => in_array((int) $site->id, $siteIds, true) && (bool) ($site->pivot?->is_default ?? false));

        return $defaultSite ? (int) $defaultSite->id : null;
    }

    /**
     * @return array<int, string>
     */
    private function businessSubjectTokens(string $query): array
    {
        $ignoredWords = array_flip([
            ...$this->stopWords(),
            ...$this->businessIntentWords(),
        ]);

        return collect($this->tokens($query, removeStopWords: false))
            ->reject(fn (string $token): bool => isset($ignoredWords[$token]))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function businessIntentWords(): array
    {
        return [
            'affaire',
            'affaires',
            'aujourd',
            'ca',
            'caisse',
            'camion',
            'ce',
            'chiffre',
            'commercial',
            'creneau',
            'creneaux',
            'demain',
            'dispo',
            'disponible',
            'est',
            'facturation',
            'facture',
            'factures',
            'hier',
            'jour',
            'libre',
            'mois',
            'planning',
            'precedent',
            'reserve',
            'reservee',
            'reserver',
            'reservation',
            'reservations',
            'semaine',
            'sites',
            'stat',
            'stats',
            'tous',
            'tout',
            'vehicule',
            'vehicules',
            'vente',
            'ventes',
        ];
    }

    /**
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }
     */
    private function businessUnavailableReply(string $message, array $destinations): array
    {
        return [
            'ok' => true,
            'message' => $message.' Voici les raccourcis disponibles avec votre compte.',
            'url' => null,
            'label' => null,
            'suggestions' => $this->suggestions($destinations, 4),
        ];
    }

    /**
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array<int, array{label: string, url: string, external: bool}>
     */
    private function suggestionsWithoutUrl(array $destinations, string $url, int $limit): array
    {
        return $this->suggestions(
            array_values(array_filter(
                $destinations,
                fn (array $destination): bool => $destination['url'] !== $url
            )),
            $limit,
        );
    }

    private function money(float $value): string
    {
        return Number::currency($value, 'EUR', locale: 'fr');
    }

    private function displayTimezone(): string
    {
        return (string) config('crm.display_timezone', config('app.timezone', 'Europe/Paris'));
    }

    private function minutesFromDateTime(CarbonImmutable $dateTime): int
    {
        return ($dateTime->hour * 60) + $dateTime->minute;
    }

    private function minutesFromTime(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hour * 60) + $minute;
    }

    private function timeLabel(int $minutes): string
    {
        return str_pad((string) intdiv($minutes, 60), 2, '0', STR_PAD_LEFT)
            .':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    private function requestedInformationField(string $query): ?string
    {
        if ($this->hasAnyToken($query, ['horaire', 'horaires', 'ouverture']) || $this->matchesAny($query, ['heure ouverture', 'heures ouverture'])) {
            return 'hours';
        }

        if ($this->hasAnyToken($query, ['email', 'mail', 'courriel']) || $this->matchesAny($query, ['e mail'])) {
            return 'email';
        }

        if ($this->hasAnyToken($query, ['telephone', 'tel', 'numero', 'portable', 'mobile'])) {
            return 'phone';
        }

        if ($this->hasAnyToken($query, ['adresse', 'localisation']) || $this->matchesAny($query, ['ou se trouve', 'ou est'])) {
            return 'address';
        }

        if ($this->matchesAny($query, ['site de', 'sites de', 'rattache', 'rattachement', 'travaille ou', 'ou travaille'])) {
            return 'sites';
        }

        if ($this->hasAnyToken($query, ['role', 'fonction', 'poste'])) {
            return 'role';
        }

        return null;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function hasAnyToken(string $query, array $needles): bool
    {
        $tokens = array_flip($this->tokens($query, removeStopWords: false));

        foreach ($needles as $needle) {
            $token = $this->normalize($needle);

            if ($token !== '' && isset($tokens[$token])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function informationSubjectTokens(string $query): array
    {
        $ignoredWords = array_flip([
            ...$this->stopWords(),
            ...$this->informationIntentWords(),
        ]);

        return collect($this->tokens($query, removeStopWords: false))
            ->reject(fn (string $token): bool => isset($ignoredWords[$token]))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function informationIntentWords(): array
    {
        return [
            'adresse',
            'affiche',
            'afficher',
            'agence',
            'agences',
            'cherche',
            'chercher',
            'connait',
            'connais',
            'contact',
            'coordonnees',
            'courriel',
            'donne',
            'donner',
            'email',
            'fonction',
            'horaire',
            'horaires',
            'localisation',
            'magasin',
            'magasins',
            'mail',
            'mobile',
            'moi',
            'numero',
            'numeros',
            'ouverture',
            'portable',
            'poste',
            'quel',
            'quelle',
            'quelles',
            'quels',
            'rattache',
            'rattachement',
            'role',
            'sais',
            'site',
            'sites',
            'tel',
            'telephone',
            'telephones',
            'travaille',
            'trouve',
            'trouver',
        ];
    }

    private function isSelfInformationQuestion(string $query): bool
    {
        return $this->matchesAny($query, [
            'ma fiche',
            'mes coordonnees',
            'mes sites',
            'mon e mail',
            'mon email',
            'mon mail',
            'mon numero',
            'mon role',
            'mon site',
            'mon tel',
            'mon telephone',
        ]);
    }

    /**
     * @return array<int, CrmUser>
     */
    private function visibleMembers(CrmUser $actor): array
    {
        $siteIds = $this->access->siteIds($actor);

        return CrmUser::query()
            ->with('sites:id,name,active')
            ->where('active', true)
            ->when($actor->role !== 'admin', function ($query) use ($actor, $siteIds): void {
                $query->where(function ($query) use ($actor, $siteIds): void {
                    $query->whereKey($actor->id);

                    if ($siteIds !== []) {
                        $query->orWhereHas('sites', function ($siteQuery) use ($siteIds): void {
                            $siteQuery
                                ->where('crm_sites.active', true)
                                ->whereIn('crm_sites.id', $siteIds);
                        });
                    }
                });
            })
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @return array<int, CrmSite>
     */
    private function visibleSites(CrmUser $actor): array
    {
        $siteIds = $this->access->siteIds($actor);

        if ($actor->role !== 'admin' && $siteIds === []) {
            return [];
        }

        return CrmSite::query()
            ->active()
            ->when($actor->role !== 'admin', fn ($query) => $query->whereIn('id', $siteIds))
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @param  array<int, string>  $subjectTokens
     * @param  array<int, CrmUser>  $members
     * @return array<int, array{score: int, member: CrmUser}>
     */
    private function rankMembers(string $query, array $subjectTokens, array $members): array
    {
        $subjectQuery = implode(' ', $subjectTokens);
        $ranked = [];

        foreach ($members as $member) {
            $member->loadMissing('sites:id,name,active');
            $haystack = $this->normalize(implode(' ', array_filter([
                $this->memberDisplayName($member),
                $member->name,
                $member->first_name,
                $member->last_name,
                $member->email,
                $member->sites->pluck('name')->implode(' '),
            ])));
            $score = $this->score($subjectQuery, $subjectTokens, $haystack, array_flip($this->tokens($haystack, removeStopWords: false)));

            if ($score > 0) {
                $ranked[] = [
                    'score' => $score + ($this->matchesAny($query, [$this->memberDisplayName($member)]) ? 6 : 0),
                    'member' => $member,
                ];
            }
        }

        usort($ranked, fn (array $first, array $second): int => $second['score'] <=> $first['score']);

        return $ranked;
    }

    /**
     * @param  array<int, string>  $subjectTokens
     * @param  array<int, CrmSite>  $sites
     * @return array<int, array{score: int, site: CrmSite}>
     */
    private function rankSites(string $query, array $subjectTokens, array $sites): array
    {
        $subjectQuery = implode(' ', $subjectTokens);
        $ranked = [];

        foreach ($sites as $site) {
            $haystack = $this->normalize(implode(' ', array_filter([
                $site->name,
                $site->slug,
                $site->address,
                $site->email,
            ])));
            $score = $this->score($subjectQuery, $subjectTokens, $haystack, array_flip($this->tokens($haystack, removeStopWords: false)));

            if ($score > 0) {
                $ranked[] = [
                    'score' => $score + ($this->matchesAny($query, [$site->name]) ? 6 : 0),
                    'site' => $site,
                ];
            }
        }

        usort($ranked, fn (array $first, array $second): int => $second['score'] <=> $first['score']);

        return $ranked;
    }

    /**
     * @param  array<int, array{score: int}>  $ranked
     */
    private function hasAmbiguousTopMatch(array $ranked): bool
    {
        return isset($ranked[1]) && $ranked[0]['score'] === $ranked[1]['score'];
    }

    /**
     * @param  array<int, array{score: int, member: CrmUser}>  $ranked
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }
     */
    private function ambiguousMembersReply(array $ranked): array
    {
        $topScore = $ranked[0]['score'];
        $names = collect($ranked)
            ->filter(fn (array $match): bool => $match['score'] === $topScore)
            ->take(4)
            ->map(fn (array $match): string => $this->memberDisplayName($match['member']))
            ->implode(', ');

        return [
            'ok' => true,
            'message' => 'J’ai trouvé plusieurs membres possibles : '.$names.'. Précisez le prénom ou le nom de famille.',
            'url' => null,
            'label' => null,
            'suggestions' => [],
        ];
    }

    /**
     * @param  array<int, array{score: int, site: CrmSite}>  $ranked
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }
     */
    private function ambiguousSitesReply(array $ranked): array
    {
        $topScore = $ranked[0]['score'];
        $names = collect($ranked)
            ->filter(fn (array $match): bool => $match['score'] === $topScore)
            ->take(4)
            ->map(fn (array $match): string => $match['site']->name)
            ->implode(', ');

        return [
            'ok' => true,
            'message' => 'J’ai trouvé plusieurs sites possibles : '.$names.'. Précisez le nom du site.',
            'url' => null,
            'label' => null,
            'suggestions' => [],
        ];
    }

    /**
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }|null
     */
    private function memberInformationReply(CrmUser $member, string $field): ?array
    {
        return match ($field) {
            'phone' => $this->knownValueReply(
                'Le téléphone de '.$this->memberDisplayName($member).' est ',
                $member->phone,
                'Je connais '.$this->memberDisplayName($member).', mais son téléphone n’est pas renseigné.',
            ),
            'email' => $this->knownValueReply(
                'L’e-mail de '.$this->memberDisplayName($member).' est ',
                $member->email,
                'Je connais '.$this->memberDisplayName($member).', mais son e-mail n’est pas renseigné.',
            ),
            'sites' => $this->memberSitesReply($member),
            'role' => $this->knownValueReply(
                'Le rôle HUB de '.$this->memberDisplayName($member).' est ',
                CrmUser::roleOptions()[$member->role] ?? $member->role,
                'Je connais '.$this->memberDisplayName($member).', mais son rôle HUB n’est pas renseigné.',
            ),
            default => null,
        };
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
    private function memberSitesReply(CrmUser $member): array
    {
        $member->loadMissing('sites:id,name,active');
        $siteNames = $member->sites
            ->filter(fn (CrmSite $site): bool => (bool) $site->active)
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        if ($siteNames === []) {
            return [
                'ok' => true,
                'message' => 'Je connais '.$this->memberDisplayName($member).', mais aucun site n’est renseigné.',
                'url' => null,
                'label' => null,
                'suggestions' => [],
            ];
        }

        return [
            'ok' => true,
            'message' => $this->memberDisplayName($member).' est rattaché '.(count($siteNames) > 1 ? 'aux sites ' : 'au site ').implode(', ', $siteNames).'.',
            'url' => null,
            'label' => null,
            'suggestions' => [],
        ];
    }

    /**
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }|null
     */
    private function siteInformationReply(CrmSite $site, string $field): ?array
    {
        return match ($field) {
            'phone' => $this->knownValueReply(
                'Le téléphone du site '.$site->name.' est ',
                $site->phone,
                'Je connais le site '.$site->name.', mais son téléphone n’est pas renseigné.',
            ),
            'email' => $this->knownValueReply(
                'L’e-mail du site '.$site->name.' est ',
                $site->email,
                'Je connais le site '.$site->name.', mais son e-mail n’est pas renseigné.',
            ),
            'address' => $this->knownValueReply(
                'L’adresse du site '.$site->name.' est ',
                $site->address,
                'Je connais le site '.$site->name.', mais son adresse n’est pas renseignée.',
            ),
            'hours' => $this->knownValueReply(
                'Les horaires du site '.$site->name.' sont ',
                $site->openingHoursLabel(),
                'Je connais le site '.$site->name.', mais ses horaires ne sont pas renseignés.',
            ),
            default => null,
        };
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
    private function knownValueReply(string $prefix, ?string $value, string $emptyMessage): array
    {
        $value = trim((string) $value);

        return [
            'ok' => true,
            'message' => $value !== '' ? $prefix.$value.'.' : $emptyMessage,
            'url' => null,
            'label' => null,
            'suggestions' => [],
        ];
    }

    /**
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }
     */
    private function teamInformationUnavailableReply(array $destinations): array
    {
        return [
            'ok' => true,
            'message' => 'Je comprends la demande, mais je ne vois pas le module Équipe dans vos accès actuels. Je ne peux donc pas afficher les coordonnées d’un membre ou d’un site.',
            'url' => null,
            'label' => null,
            'suggestions' => $this->suggestions($destinations, 4),
        ];
    }

    private function memberDisplayName(CrmUser $member): string
    {
        $fullName = trim(implode(' ', array_filter([
            $member->first_name,
            $member->last_name,
        ])));

        return $fullName !== '' ? $fullName : $member->name;
    }

    /**
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @return array{
     *     ok: true,
     *     message: string,
     *     url: string|null,
     *     label: string|null,
     *     suggestions: array<int, array{label: string, url: string, external: bool}>
     * }|null
     */
    private function guidedReply(string $message, array $destinations): ?array
    {
        $query = $this->normalize($message);
        $tokens = $this->tokens($query, removeStopWords: false);

        if ($query === '' || $tokens === []) {
            return null;
        }

        if ($this->isPoliteGreeting($tokens)) {
            return [
                'ok' => true,
                'message' => 'Bonjour. Je peux vous aider à trouver une page, comprendre où faire une action courante ou vous guider dans le HUB. Dites-moi par exemple : congés, équipe, véhicule, profil, caisse ou administration.',
                'url' => null,
                'label' => null,
                'suggestions' => $this->suggestions($destinations, 4),
            ];
        }

        if ($this->isThankYou($tokens)) {
            return [
                'ok' => true,
                'message' => 'Avec plaisir. Je reste disponible si vous cherchez une page, une action à faire dans le HUB ou un raccourci utile.',
                'url' => null,
                'label' => null,
                'suggestions' => $this->suggestions($destinations, 4),
            ];
        }

        if ($this->matchesAny($query, ['aide', 'aider', 'tu peux', 'vous pouvez', 'comment ca marche', 'que faire', 'quoi faire', 'question courante', 'questions courantes'])) {
            return [
                'ok' => true,
                'message' => 'Je peux répondre aux demandes courantes du HUB : trouver une page, expliquer où poser un congé, où consulter les coordonnées d’une équipe, où réserver un véhicule, où vérifier la caisse, où gérer le profil ou où aller pour l’administration.',
                'url' => null,
                'label' => null,
                'suggestions' => $this->suggestions($destinations, 4),
            ];
        }

        if ($this->matchesAny($query, ['deconnexion', 'déconnexion', 'deconnecter', 'déconnecter', 'logout', 'sortir'])) {
            return [
                'ok' => true,
                'message' => 'Pour vous déconnecter, ouvrez le menu utilisateur en haut à droite puis cliquez sur Se déconnecter.',
                'url' => null,
                'label' => null,
                'suggestions' => $this->suggestions($destinations, 4),
            ];
        }

        foreach ($this->commonTopics() as $topic) {
            if (! $this->matchesAny($query, $topic['triggers'])) {
                continue;
            }

            $destination = $this->firstDestinationMatching($destinations, $topic['destinations']);

            if (! $destination) {
                return [
                    'ok' => true,
                    'message' => 'Je comprends la demande, mais je ne vois pas ce module dans vos accès actuels. Voici les raccourcis disponibles avec votre compte.',
                    'url' => null,
                    'label' => null,
                    'suggestions' => $this->suggestions($destinations, 4),
                ];
            }

            return [
                'ok' => true,
                'message' => $topic['message'],
                'url' => $destination['url'],
                'label' => 'Ouvrir '.$destination['label'],
                'suggestions' => $this->suggestions(
                    array_values(array_filter(
                        $destinations,
                        fn (array $availableDestination): bool => $availableDestination['url'] !== $destination['url']
                    )),
                    3,
                ),
            ];
        }

        return null;
    }

    /**
     * @return array<int, array{
     *     triggers: array<int, string>,
     *     destinations: array<int, string>,
     *     message: string
     * }>
     */
    private function commonTopics(): array
    {
        return [
            [
                'triggers' => ['conge', 'conges', 'absence', 'absences', 'vacance', 'vacances', 'solde conge', 'poser conge', 'demander absence'],
                'destinations' => ['conges', 'absence', 'vacance'],
                'message' => 'Pour les congés et absences, ouvrez Congés & Absences. Vous y trouverez votre calendrier, vos soldes et les demandes. Pour créer une demande, utilisez le bouton + Demander une absence.',
            ],
            [
                'triggers' => ['equipe', 'membre', 'membres', 'adresse site', 'telephone site', 'coordonnees site', 'annuaire', 'qui travaille'],
                'destinations' => ['equipe', 'membres', 'annuaire', 'site'],
                'message' => 'Pour les informations d’équipe, ouvrez Équipe. Le site sélectionné dans la barre du haut permet de voir les membres, les coordonnées du site, l’adresse, le téléphone, l’e-mail et les horaires.',
            ],
            [
                'triggers' => ['vehicule', 'vehicules', 'camion', 'sprinter', 'reservation vehicule', 'planning vehicule'],
                'destinations' => ['reservation', 'vehicule', 'camion', 'sprinter'],
                'message' => 'Pour les véhicules, ouvrez Réservations véhicules. Vous pourrez choisir un véhicule du site, consulter le planning, voir les créneaux libres et créer une réservation si vos droits le permettent.',
            ],
            [
                'triggers' => ['chiffre', 'chiffre affaire', 'ca', 'ventes', 'objectif', 'objectifs', 'commission', 'commissions', 'pilotage commercial', 'stats', 'statistiques'],
                'destinations' => ['pilotage', 'commercial', 'stats', 'statistiques'],
                'message' => 'Pour le chiffre, les objectifs et les statistiques commerciales, ouvrez Pilotage commercial ou Stats selon vos accès. Je peux aussi répondre directement à une question comme “quel est mon chiffre d’hier ?” quand les droits nécessaires sont actifs.',
            ],
            [
                'triggers' => ['materiel', 'location', 'outil', 'outillage', 'machine', 'disponible'],
                'destinations' => ['location', 'materiel', 'outil', 'outillage'],
                'message' => 'Pour le matériel, ouvrez Location matériel. C’est l’endroit prévu pour consulter le stock, les disponibilités et créer une location.',
            ],
            [
                'triggers' => ['rapport visite', 'rapports visite', 'tournee', 'tournees', 'visite commercial', 'visites commerciales', 'client a visiter'],
                'destinations' => ['rapport', 'visite', 'tournee', 'representant'],
                'message' => 'Pour les visites commerciales, ouvrez Rapport de visite. Vous pourrez consulter les tournées, préparer une visite et suivre les comptes rendus selon vos droits.',
            ],
            [
                'triggers' => ['profil', 'compte', 'photo', 'email', 'e mail', 'telephone', 'mot de passe', 'coordonnees', 'preferences'],
                'destinations' => ['parametres', 'profil', 'compte', 'mot de passe'],
                'message' => 'Pour votre profil, ouvrez Paramètres du compte. Vous pouvez y gérer les informations affichées dans le HUB, la photo, l’e-mail, le téléphone et les préférences disponibles.',
            ],
            [
                'triggers' => ['caisse', 'controle caisse', 'ticket', 'ecart caisse', 'anomalie caisse'],
                'destinations' => ['controle', 'caisse', 'ticket'],
                'message' => 'Pour la caisse, ouvrez Contrôle caisse. Vous y trouverez les contrôles, les écarts éventuels et les éléments à vérifier.',
            ],
            [
                'triggers' => ['cheque', 'cheques', 'remise cheque', 'remise cheques', 'banque', 'depot cheque'],
                'destinations' => ['remise', 'cheque', 'banque'],
                'message' => 'Pour les chèques, ouvrez Remise de chèques. Vous pourrez consulter les remises existantes et créer une nouvelle remise si vos droits le permettent.',
            ],
            [
                'triggers' => ['acompte', 'demande acompte', 'avance client', 'paiement client'],
                'destinations' => ['acompte', 'demande', 'paiement'],
                'message' => 'Pour les acomptes, ouvrez Demande d’acompte. C’est le module prévu pour suivre ou créer les demandes liées aux paiements clients.',
            ],
            [
                'triggers' => ['document', 'documents', 'procedure', 'procedures', 'fiche technique', 'fiches techniques', 'promo', 'catalogue'],
                'destinations' => ['documents', 'procedures', 'fiches techniques', 'promo', 'catalogue'],
                'message' => 'Pour les documents, ouvrez le module Documents ou la page interne correspondante. Vous pourrez retrouver les procédures, fiches techniques, supports promo ou contenus HUB visibles avec votre compte.',
            ],
            [
                'triggers' => ['tapis', 'romus', 'plan tapis', 'mesure tapis', 'coupe tapis'],
                'destinations' => ['tapis', 'romus'],
                'message' => 'Pour Tapis ROMUS, ouvrez le module Tapis ROMUS. Il regroupe les outils liés aux mesures, plans et suivis du module.',
            ],
            [
                'triggers' => ['admin', 'administration', 'utilisateur', 'utilisateurs', 'site', 'sites', 'permission', 'permissions', 'module', 'modules', 'role', 'roles'],
                'destinations' => ['administration', 'utilisateurs', 'sites', 'permissions', 'modules'],
                'message' => 'Pour l’administration, ouvrez Administration. Vous y gérez les utilisateurs HUB, les sites, les modules, les menus, les rôles et les permissions selon vos droits.',
            ],
        ];
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function isPoliteGreeting(array $tokens): bool
    {
        $greetings = ['bonjour', 'bonsoir', 'salut', 'coucou', 'hello', 'hey'];
        $politeWords = ['bonjour', 'bonsoir', 'salut', 'coucou', 'hello', 'hey', 'ca', 'va', 'svp', 'stp'];

        return collect($tokens)->intersect($greetings)->isNotEmpty()
            && collect($tokens)->diff($politeWords)->isEmpty();
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function isThankYou(array $tokens): bool
    {
        $thankYouWords = ['merci', 'parfait', 'super', 'ok'];
        $politeWords = ['merci', 'parfait', 'super', 'ok', 'beaucoup', 'bien'];

        return collect($tokens)->intersect($thankYouWords)->isNotEmpty()
            && collect($tokens)->diff($politeWords)->isEmpty();
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function matchesAny(string $query, array $needles): bool
    {
        foreach ($needles as $needle) {
            $normalizedNeedle = $this->normalize($needle);

            if ($normalizedNeedle !== '' && str_contains($query, $normalizedNeedle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{label: string, url: string, keywords: array<int, string>, external: bool}>  $destinations
     * @param  array<int, string>  $needles
     * @return array{label: string, url: string, keywords: array<int, string>, external: bool}|null
     */
    private function firstDestinationMatching(array $destinations, array $needles): ?array
    {
        foreach ($destinations as $destination) {
            $haystack = $this->normalize(implode(' ', [
                $destination['label'],
                $destination['url'],
                ...$destination['keywords'],
            ]));

            if ($this->matchesAny($haystack, $needles)) {
                return $destination;
            }
        }

        return null;
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
            'stats' => ['stats', 'statistiques', 'chiffre affaire', 'facturation', 'clients', 'familles produits', 'comparaison'],
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
