<?php

namespace Modules\CrmTeams\Services;

use App\Models\CrmUser;
use App\Models\User;
use App\Support\CrmTheme;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\CrmCore\Services\CrmAccessService;
use Modules\CrmCore\Support\CrmReferenceCache;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TeamService
{
    public function __construct(
        private readonly CrmAccessService $access,
    ) {}

    public function actorForUser(User $user): CrmUser
    {
        $actor = CrmUser::query()
            ->with(['modules:id,slug,active', 'permissions:id,name,label', 'sites:id'])
            ->forAccount($user)
            ->where('active', true)
            ->first();

        if (! $actor) {
            $this->fail('Utilisateur HUB introuvable', 404);
        }

        return $actor;
    }

    public function bootstrap(CrmUser $actor, ?int $requestedSiteId = null, bool $allSites = false): array
    {
        $siteIds = $this->access->siteIdsForModule($actor, 'equipes', ['teams.view']);

        if ($siteIds === []) {
            $this->fail('Aucun site autorise pour le module equipe', 403);
        }

        $selectedSiteId = $this->selectedSiteId($actor, $requestedSiteId, $siteIds);
        if (! $selectedSiteId) {
            $this->fail('Aucun site disponible', 403);
        }

        return [
            'ok' => true,
            'mode' => 'mysql',
            'user' => $this->actorRow($actor, $siteIds, $selectedSiteId),
            'selectedSiteId' => $selectedSiteId,
            'allSites' => $allSites,
            'sites' => $this->siteRows($siteIds),
            'members' => $this->memberRows($actor, $allSites ? $siteIds : [$selectedSiteId]),
        ];
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    private function selectedSiteId(CrmUser $actor, ?int $requestedSiteId, array $siteIds): ?int
    {
        if ($requestedSiteId && in_array($requestedSiteId, $siteIds, true)) {
            return $requestedSiteId;
        }

        $defaultSiteId = (int) (collect(CrmReferenceCache::activeUserRows())
            ->firstWhere('id', $actor->id)['defaultSiteId'] ?? 0);

        if ($defaultSiteId > 0 && in_array($defaultSiteId, $siteIds, true)) {
            return $defaultSiteId;
        }

        return $siteIds[0] ?? null;
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, array{id: int, name: string, slug: string, active: bool, membersCount: int, address: string, phone: string, email: string, color: string, photoUrl: string, showPhotoInHeader: bool, hours: array{morningStart: string, morningEnd: string, afternoonStart: string, afternoonEnd: string}}>
     */
    private function siteRows(array $siteIds): array
    {
        $siteLookup = array_fill_keys($siteIds, true);
        $counts = array_fill_keys($siteIds, 0);

        foreach (CrmReferenceCache::activeUserRows() as $user) {
            foreach ($user['siteIds'] as $siteId) {
                if (isset($siteLookup[$siteId])) {
                    $counts[$siteId]++;
                }
            }
        }

        return collect(CrmReferenceCache::activeSiteRows())
            ->filter(fn (array $site): bool => isset($siteLookup[(int) $site['id']]))
            ->sortBy('name')
            ->map(fn (array $site): array => [
                'id' => (int) $site['id'],
                'name' => $site['name'],
                'slug' => $site['slug'],
                'active' => true,
                'membersCount' => (int) ($counts[(int) $site['id']] ?? 0),
                'address' => $site['address'] ?? '',
                'phone' => $site['phone'] ?? '',
                'email' => $site['email'] ?? '',
                'color' => $site['color'] ?? CrmTheme::primaryHex(),
                'photoUrl' => $site['photoUrl'] ?? '',
                'showPhotoInHeader' => (bool) ($site['showPhotoInHeader'] ?? true),
                'hours' => $site['hours'] ?? [
                    'morningStart' => '07:30',
                    'morningEnd' => '12:00',
                    'afternoonStart' => '13:30',
                    'afternoonEnd' => '17:30',
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, array<string, mixed>>
     */
    private function memberRows(CrmUser $actor, array $siteIds): array
    {
        $siteLookup = array_fill_keys($siteIds, true);
        $members = collect(CrmReferenceCache::activeUserRows())
            ->filter(fn (array $member): bool => $this->memberBelongsToSiteLookup($member, $siteLookup))
            ->sortBy(fn (array $member): string => sprintf(
                '%s|%s|%s',
                $member['firstName'] ?: $member['name'],
                $member['lastName'] ?: $member['name'],
                $member['name'],
            ))
            ->values();

        $canViewPresence = $this->canViewPresence($actor);
        $presenceByMemberId = $canViewPresence
            ? $this->presenceByMemberId($members->pluck('id')->map(fn ($id): int => (int) $id)->all())
            : [];

        return $members
            ->map(fn (array $member): array => $this->memberRow(
                $member,
                $canViewPresence ? ($presenceByMemberId[(int) $member['id']] ?? ['isOnline' => false, 'lastSeenAt' => null]) : null,
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array{siteIds: array<int, int>}  $member
     * @param  array<int, bool>  $siteLookup
     */
    private function memberBelongsToSiteLookup(array $member, array $siteLookup): bool
    {
        foreach ($member['siteIds'] as $siteId) {
            if (isset($siteLookup[(int) $siteId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{id: int, name: string, firstName: string, lastName: string, email: string, phone: string, role: string, photoUrl: string, siteIds: array<int, int>, siteNames: array<int, string>, defaultSiteId: int|null, defaultSiteName?: string}  $member
     * @param  array{isOnline: bool, lastSeenAt: string|null}|null  $presence
     */
    private function memberRow(array $member, ?array $presence = null): array
    {
        [$firstName, $lastName] = $this->splitName($member['name'], $member['firstName'], $member['lastName']);

        $row = [
            'id' => $member['id'],
            'name' => trim($firstName.' '.$lastName) ?: $member['name'],
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phone' => $member['phone'],
            'email' => $member['email'],
            'role' => $member['role'],
            'photoUrl' => $member['photoUrl'],
            'siteIds' => $member['siteIds'],
            'siteNames' => $member['siteNames'],
            'primarySiteId' => $member['defaultSiteId'],
            'primarySiteName' => $this->primarySiteName($member),
        ];

        if ($presence !== null) {
            $row['presence'] = [
                'isOnline' => $presence['isOnline'],
                'label' => $presence['isOnline'] ? 'En ligne' : 'Hors ligne',
                'lastSeenAt' => $presence['lastSeenAt'],
            ];
        }

        return $row;
    }

    /**
     * @param  array{siteNames: array<int, string>, defaultSiteName?: string}  $member
     */
    private function primarySiteName(array $member): string
    {
        $defaultSiteName = trim((string) ($member['defaultSiteName'] ?? ''));

        if ($defaultSiteName !== '') {
            return $defaultSiteName;
        }

        return trim((string) ($member['siteNames'][0] ?? ''));
    }

    /**
     * @param  array<int, int>  $memberIds
     * @return array<int, array{isOnline: bool, lastSeenAt: string|null}>
     */
    private function presenceByMemberId(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $table = (string) config('session.table', 'sessions');
        if (! Schema::hasTable($table)) {
            return [];
        }

        $accountIdsByMemberId = CrmUser::query()
            ->whereIn('id', $memberIds)
            ->pluck('id', 'id')
            ->map(fn ($accountId): int => (int) $accountId)
            ->all();

        if ($accountIdsByMemberId === []) {
            return [];
        }

        $minimumActivity = now()->subMinutes((int) config('session.lifetime', 120))->timestamp;
        $onlineActivity = now()->subMinutes(5)->timestamp;

        $lastActivityByAccountId = DB::table($table)
            ->select('user_id', DB::raw('MAX(last_activity) as last_activity'))
            ->whereIn('user_id', array_values($accountIdsByMemberId))
            ->where('last_activity', '>=', $minimumActivity)
            ->groupBy('user_id')
            ->pluck('last_activity', 'user_id')
            ->map(fn ($lastActivity): int => (int) $lastActivity)
            ->all();

        return collect($accountIdsByMemberId)
            ->mapWithKeys(function (int $accountId, int $memberId) use ($lastActivityByAccountId, $onlineActivity): array {
                $lastActivity = (int) ($lastActivityByAccountId[$accountId] ?? 0);

                return [
                    $memberId => [
                        'isOnline' => $lastActivity >= $onlineActivity,
                        'lastSeenAt' => $lastActivity > 0 ? date('c', $lastActivity) : null,
                    ],
                ];
            })
            ->all();
    }

    private function canViewPresence(CrmUser $actor): bool
    {
        return $actor->role === 'admin';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name, string $firstName, string $lastName): array
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);

        if ($firstName === '') {
            $rawName = trim($name);
            if ($rawName === 'J-Philippe') {
                return ['Jean-Philippe', $lastName];
            }

            $parts = preg_split('/\s+/', $rawName, 2);
            $firstName = trim((string) ($parts[0] ?? ''));

            if ($lastName === '') {
                $lastName = trim((string) ($parts[1] ?? ''));
            }
        }

        return [$firstName, $lastName];
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    private function actorRow(CrmUser $actor, array $siteIds, int $selectedSiteId): array
    {
        return [
            'id' => (int) $actor->id,
            'name' => $actor->name,
            'role' => $actor->role,
            'siteIds' => $siteIds,
            'selectedSiteId' => $selectedSiteId,
            'canViewPresence' => $this->canViewPresence($actor),
        ];
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpException($status, $message);
    }
}
