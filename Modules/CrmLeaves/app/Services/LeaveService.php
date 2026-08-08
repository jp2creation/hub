<?php

namespace Modules\CrmLeaves\Services;

use App\Enums\CrmLeavePeriod;
use App\Enums\CrmLeaveStatus;
use App\Enums\CrmLeaveType;
use App\Models\CrmLeaveEmployee;
use App\Models\CrmLeaveEntry;
use App\Models\CrmLeaveTypeSetting;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use App\Support\CrmTheme;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\CrmCore\Queries\ReservationConflictQuery;
use Modules\CrmCore\Services\CrmAccessService;
use Modules\CrmCore\Services\CrmActivityLogger;
use Modules\CrmCore\Services\CrmImageStorage;
use Modules\CrmLeaves\Exceptions\LeaveApiException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LeaveService
{
    private const MODULE = 'conges';

    private const VIEW_PERMISSIONS = ['conges.view', 'conges.manage', 'conges.manage_types'];

    private const EXPORT_PERMISSIONS = ['conges.view', 'conges.manage'];

    private const TYPE_MANAGEMENT_PERMISSIONS = ['conges.manage', 'conges.manage_types'];

    public function __construct(
        private readonly ReservationConflictQuery $conflicts,
        private readonly CrmActivityLogger $activity,
        private readonly CrmAccessService $access,
        private readonly LeaveBalanceService $balances,
        private readonly LeavePdfExportService $pdfExport,
        private readonly CrmImageStorage $images,
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

    public function bootstrap(CrmUser $actor, ?int $siteId = null): array
    {
        $this->requireModule($actor);

        $selectedSiteId = $this->resolveSiteId($actor, $siteId);
        $this->requireAnySitePermission($actor, $selectedSiteId, self::VIEW_PERMISSIONS);

        $sites = $this->availableSites($actor);
        $employees = $this->syncEmployeesForSite($selectedSiteId);
        $employeeIds = $employees->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $canManage = $this->canOnSite($actor, $selectedSiteId, 'conges.manage');
        $canManageTypes = $this->canManageTypes($actor, $selectedSiteId);
        $canExport = $this->hasAnySitePermission($actor, $selectedSiteId, self::EXPORT_PERMISSIONS);
        $types = $this->leaveTypeRows();

        $leaves = CrmLeaveEntry::query()
            ->with('employee')
            ->join('crm_leave_employees as employees', 'employees.id', '=', 'crm_leave_entries.employee_id')
            ->select('crm_leave_entries.*')
            ->whereIn('crm_leave_entries.employee_id', $employeeIds)
            ->orderBy('crm_leave_entries.start_date')
            ->orderBy('crm_leave_entries.end_date')
            ->orderBy('employees.sort_order')
            ->orderBy('employees.name')
            ->get();
        $year = (int) now(config('crm.display_timezone', config('app.timezone', 'Europe/Paris')))->year;

        return [
            'ok' => true,
            'user' => [
                'id' => $actor->id,
                'employeeId' => $employees
                    ->first(fn (CrmLeaveEmployee $employee): bool => (int) $employee->crm_user_id === (int) $actor->id)
                    ?->id,
                'name' => $actor->name,
                'role' => $actor->role,
                'permissions' => $this->access->permissionNames($actor),
                'canManage' => $canManage,
                'canViewTeam' => $canManage,
                'canViewBalances' => $canManage,
                'canViewRequests' => true,
                'canViewReports' => $canManage,
                'canManageSettings' => $canManageTypes,
                'canManageTypes' => $canManageTypes,
                'canCreateRequest' => true,
                'canExport' => $canExport,
                'canExportOtherSites' => $canExport && $canManage && $sites->count() > 1,
                'siteIds' => $this->siteIds($actor),
                'selectedSiteId' => $selectedSiteId,
            ],
            'sites' => $sites->map(fn (CrmSite $site): array => $this->siteRow($site))->values()->all(),
            'selectedSiteId' => $selectedSiteId,
            'employees' => $employees->map(fn (CrmLeaveEmployee $employee): array => $this->employeeRow($employee))->values()->all(),
            'leaves' => $leaves->map(fn (CrmLeaveEntry $entry): array => $this->entryRow($entry))->values()->all(),
            'balances' => $this->balances->rowsForEmployees($employeeIds, $year),
            'balanceYear' => $year,
            'types' => $types,
            'periods' => [
                ['value' => CrmLeavePeriod::Full->value, 'label' => CrmLeavePeriod::Full->label()],
                ['value' => CrmLeavePeriod::Morning->value, 'label' => CrmLeavePeriod::Morning->label()],
                ['value' => CrmLeavePeriod::Afternoon->value, 'label' => CrmLeavePeriod::Afternoon->label()],
            ],
            'export' => $canExport
                ? $this->exportOptions($actor, ['siteId' => $selectedSiteId])
                : ['ok' => true, 'siteIds' => [$selectedSiteId], 'canIncludeOtherSites' => false, 'employees' => []],
        ];
    }

    public function exportOptions(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        $siteId = $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null);
        $selectedSiteId = $this->resolveSiteId($actor, $siteId);
        $includeOtherSites = $this->booleanValue($data['includeOtherSites'] ?? $data['include_other_sites'] ?? false);
        $siteIds = $this->exportSiteIds($actor, $selectedSiteId, $includeOtherSites);
        $employees = $this->exportableEmployees($actor, $selectedSiteId, $this->syncEmployeesForSites($siteIds));
        $sites = CrmSite::query()
            ->active()
            ->whereIn('id', $siteIds)
            ->orderedForHub()
            ->get()
            ->keyBy('id');

        return [
            'ok' => true,
            'siteIds' => $siteIds,
            'canIncludeOtherSites' => $this->canIncludeOtherSites($actor, $selectedSiteId),
            'employees' => $employees
                ->map(fn (CrmLeaveEmployee $employee): array => $this->exportEmployeeRow($employee, $siteIds, $sites))
                ->values()
                ->all(),
        ];
    }

    public function exportPdf(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        [$from, $to] = $this->exportDateRange($data);
        $siteId = $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null);
        $includeOtherSites = $this->booleanValue($data['includeOtherSites'] ?? $data['include_other_sites'] ?? false);
        $siteIds = $this->exportSiteIds($actor, $siteId, $includeOtherSites);
        $selectedSiteId = $this->resolveSiteId($actor, $siteId);
        $employees = $this->exportableEmployees($actor, $selectedSiteId, $this->syncEmployeesForSites($siteIds));
        $requestedEmployeeIds = $this->integerList($data['employeeIds'] ?? $data['employee_ids'] ?? []);

        if ($requestedEmployeeIds !== []) {
            $allowedEmployeeIds = $employees->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $unauthorizedIds = array_values(array_diff($requestedEmployeeIds, $allowedEmployeeIds));

            if ($unauthorizedIds !== []) {
                $this->fail('Utilisateur non autorise pour cet export', 403);
            }

            $employees = $employees->filter(fn (CrmLeaveEmployee $employee): bool => in_array((int) $employee->id, $requestedEmployeeIds, true))->values();
        }

        if ($employees->isEmpty()) {
            $this->fail('Aucun membre a exporter', 422, 'empty_leave_export');
        }

        $employeeIds = $employees->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $entries = CrmLeaveEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', '<>', CrmLeaveStatus::Refused->value)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get()
            ->map(fn (CrmLeaveEntry $entry): array => $this->exportEntryPdfRow($entry));

        $sites = CrmSite::query()
            ->active()
            ->whereIn('id', $siteIds)
            ->orderedForHub()
            ->pluck('name')
            ->map(fn ($name): string => (string) $name)
            ->all();

        $pdfEmployees = $employees
            ->map(fn (CrmLeaveEmployee $employee): array => $this->exportEmployeePdfRow($employee))
            ->values();

        $filename = sprintf('conges-%s-%s.pdf', $from->format('Ymd'), $to->format('Ymd'));

        $this->activity->log(
            $actor,
            'export conges pdf',
            'Export conges '.$from->toDateString().' au '.$to->toDateString().' - '.$pdfEmployees->count().' membre(s)',
        );

        return [
            'filename' => $filename,
            'contents' => $this->pdfExport->render($pdfEmployees, $entries, $from, $to, $sites),
        ];
    }

    public function saveLeave(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = max(0, (int) ($data['id'] ?? 0));
            $employeeId = (int) ($data['employeeId'] ?? $data['employee_id'] ?? 0);
            $siteId = $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null);

            if ($employeeId <= 0) {
                $this->fail('Utilisateur HUB requis', 400);
            }

            $startDate = $this->date((string) ($data['startDate'] ?? $data['start_date'] ?? ''), 'debut');
            $endDate = $this->date((string) ($data['endDate'] ?? $data['end_date'] ?? $startDate), 'fin');

            if ($endDate < $startDate) {
                $this->fail('La date de fin doit etre apres le debut', 400);
            }

            if (CarbonImmutable::parse($startDate)->diffInDays(CarbonImmutable::parse($endDate)) > 370) {
                $this->fail('Periode trop longue', 400);
            }

            $period = $this->choice((string) ($data['period'] ?? 'full'), CrmLeavePeriod::values(), CrmLeavePeriod::Full->value);
            $requestedStatus = $this->choice((string) ($data['status'] ?? 'approved'), CrmLeaveStatus::values(), CrmLeaveStatus::Approved->value);

            if ($period !== CrmLeavePeriod::Full->value && $startDate !== $endDate) {
                $this->fail('Une demi-journee doit commencer et finir le meme jour', 400, 'invalid_half_day_period');
            }

            $durationDays = $this->balances->durationDays($startDate, $endDate, $period);

            if ($durationDays <= 0) {
                $this->fail('Duree de conge invalide', 400, 'invalid_leave_duration');
            }

            $employee = CrmLeaveEmployee::query()
                ->where('active', true)
                ->lockForUpdate()
                ->find($employeeId);

            if (! $employee) {
                $this->fail('Utilisateur HUB introuvable', 404);
            }

            $selectedSiteId = $this->requireEmployeeSiteAccess($actor, $employee, $siteId);
            $canManageSelectedSite = $this->canOnSite($actor, $selectedSiteId, 'conges.manage');
            $isOwnEmployee = $this->isEmployeeLinkedToActor($employee, $actor);

            $entry = $id > 0
                ? CrmLeaveEntry::query()->lockForUpdate()->find($id)
                : new CrmLeaveEntry;

            if ($id > 0 && ! $entry) {
                $this->fail('Conge introuvable', 404);
            }

            if ($entry->exists && $entry->status === CrmLeaveStatus::Approved->value && $actor->role !== 'admin') {
                $this->fail('Un conge valide ne peut etre modifie que par un administrateur', 403, 'approved_leave_locked');
            }

            $canManageCurrentSite = $canManageSelectedSite;
            $isOwnCurrentEntry = false;

            if ($entry->exists) {
                $currentEmployee = CrmLeaveEmployee::query()
                    ->lockForUpdate()
                    ->find((int) $entry->employee_id);

                if (! $currentEmployee) {
                    $this->fail('Utilisateur HUB introuvable', 404);
                }

                $currentSiteId = $this->requireEmployeeSiteAccess($actor, $currentEmployee, $siteId);
                $canManageCurrentSite = $this->canOnSite($actor, $currentSiteId, 'conges.manage');
                $isOwnCurrentEntry = $this->isEmployeeLinkedToActor($currentEmployee, $actor);
            }

            if (! $canManageSelectedSite && ! $isOwnEmployee) {
                $this->fail('Droit insuffisant : conges.manage', 403);
            }

            if ($entry->exists && ! $canManageCurrentSite) {
                if (! $isOwnCurrentEntry || $entry->status !== CrmLeaveStatus::Pending->value) {
                    $this->fail('Seules vos demandes en attente peuvent etre modifiees', 403, 'pending_leave_required');
                }

                if ((int) $entry->employee_id !== $employeeId) {
                    $this->fail('Utilisateur HUB non modifiable sur une demande personnelle', 403, 'employee_change_forbidden');
                }
            }

            $type = $this->leaveTypeValue(
                (string) ($data['type'] ?? CrmLeaveType::PaidLeave->value),
                $entry->exists ? (string) $entry->type : null,
            );
            $requiresApproval = $this->leaveTypeRequiresApproval($type);
            $status = ($canManageSelectedSite && $canManageCurrentSite)
                ? $requestedStatus
                : ($entry->exists
                    ? (string) $entry->status
                    : ($requiresApproval ? CrmLeaveStatus::Pending->value : CrmLeaveStatus::Approved->value));

            if ($status !== CrmLeaveStatus::Refused->value && $this->conflicts->leaveOverlaps($employeeId, $startDate, $endDate, $period, $id > 0 ? $id : null)) {
                $this->fail('Un conge existe deja sur cette periode', 409, 'leave_overlap');
            }

            $year = CarbonImmutable::parse($startDate)->year;

            if (
                $status === CrmLeaveStatus::Approved->value
                && (bool) config('crm.leaves.enforce_balances', false)
                && $this->leaveTypeConsumesBalance($type)
                && ! $this->balances->canRequest($employeeId, $type, $year, $durationDays, $entry->exists ? $entry->id : null)
            ) {
                $this->fail('Solde insuffisant pour ce conge', 422, 'insufficient_balance');
            }

            $oldAttributes = $entry->exists ? $entry->only([
                'employee_id',
                'start_date',
                'end_date',
                'type',
                'period',
                'duration_days',
                'status',
            ]) : [];

            $entry->fill([
                'employee_id' => $employeeId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'type' => $type,
                'period' => $period,
                'duration_days' => $durationDays,
                'status' => $status,
                'notes' => trim((string) ($data['notes'] ?? '')),
                'source' => $entry->exists ? $entry->source : 'crm',
                'created_by' => $entry->exists ? $entry->created_by : $actor->id,
                'updated_by' => $actor->id,
            ]);
            $entry->save();
            $this->balances->recordSavedEntry($entry, $actor, $oldAttributes);

            $this->activity->log(
                $actor,
                $id > 0 ? 'modification conge' : 'creation conge',
                "Conge #{$entry->id} - {$employee->name} - {$startDate} au {$endDate}",
            );

            return ['ok' => true, 'leave' => $this->entryRow($entry->refresh()->load('employee'))];
        });
    }

    public function approveLeave(CrmUser $actor, array $data): array
    {
        return $this->reviewLeave($actor, $data, CrmLeaveStatus::Approved);
    }

    public function refuseLeave(CrmUser $actor, array $data): array
    {
        return $this->reviewLeave($actor, $data, CrmLeaveStatus::Refused);
    }

    public function deleteLeave(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);
            $siteId = $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null);

            if ($id <= 0) {
                $this->fail('Conge requis', 400);
            }

            $entry = CrmLeaveEntry::query()->lockForUpdate()->find($id);

            if (! $entry) {
                $this->fail('Conge introuvable', 404);
            }

            if ($entry->status === CrmLeaveStatus::Approved->value && $actor->role !== 'admin') {
                $this->fail('Un conge valide ne peut etre supprime que par un administrateur', 403, 'approved_leave_locked');
            }

            $employee = CrmLeaveEmployee::query()->lockForUpdate()->find((int) $entry->employee_id);
            if (! $employee) {
                $this->fail('Utilisateur HUB introuvable', 404);
            }

            $selectedSiteId = $this->requireEmployeeSiteAccess($actor, $employee, $siteId);
            $canManage = $this->canOnSite($actor, $selectedSiteId, 'conges.manage');
            $isOwnPendingRequest = $this->isEmployeeLinkedToActor($employee, $actor)
                && $entry->status === CrmLeaveStatus::Pending->value;

            if (! $canManage && ! $isOwnPendingRequest) {
                $this->fail('Droit insuffisant : conges.manage', 403);
            }

            $snapshot = $entry->only([
                'id',
                'employee_id',
                'start_date',
                'end_date',
                'type',
                'period',
                'duration_days',
                'status',
            ]);

            $entry->delete();
            $this->balances->recordDeletedEntry($snapshot, $actor);

            $this->activity->log($actor, 'suppression conge', "Conge #{$id} - {$employee->name}");

            return ['ok' => true, 'deleted' => true];
        });
    }

    public function saveLeaveType(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $siteId = $this->resolveSiteId($actor, $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null));
            $this->requireTypeManagement($actor, $siteId);

            $id = (int) ($data['id'] ?? 0);
            $label = Str::squish((string) ($data['label'] ?? $data['name'] ?? ''));

            if ($label === '') {
                $this->fail('Nom du type requis', 422, 'leave_type_label_required');
            }

            if (Str::length($label) > 80) {
                $this->fail('Nom du type trop long', 422, 'leave_type_label_too_long');
            }

            $color = $this->leaveTypeColor((string) ($data['color'] ?? '#38bdf8'));
            $type = $id > 0
                ? CrmLeaveTypeSetting::query()->lockForUpdate()->find($id)
                : new CrmLeaveTypeSetting;

            if ($id > 0 && ! $type) {
                $this->fail("Type d'absence introuvable", 404, 'leave_type_not_found');
            }

            $sortOrder = array_key_exists('sortOrder', $data)
                ? (int) $data['sortOrder']
                : (array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : null);

            $value = $type->exists
                ? (string) $type->value
                : $this->uniqueLeaveTypeValue((string) ($data['value'] ?? $label));

            $type->fill([
                'value' => $value,
                'label' => $label,
                'color' => $color,
                'active' => array_key_exists('active', $data) ? $this->booleanValue($data['active']) : ($type->exists ? (bool) $type->active : true),
                'requires_balance' => $this->booleanValue($data['requiresBalance'] ?? $data['requires_balance'] ?? false),
                'requires_approval' => $this->leaveTypeRequiresApproval($value),
                'send_reminders' => $this->booleanValue($data['sendReminders'] ?? $data['send_reminders'] ?? true),
                'is_system' => $type->exists ? (bool) $type->is_system : false,
                'sort_order' => $sortOrder ?: ($type->exists ? (int) $type->sort_order : $this->nextLeaveTypeSortOrder()),
            ]);

            if (! $type->active && $this->activeLeaveTypeCount($type->exists ? (int) $type->id : null) <= 0) {
                $this->fail('Gardez au moins un type visible', 422, 'last_visible_leave_type');
            }

            $type->save();

            $this->activity->log(
                $actor,
                $id > 0 ? 'modification type conge' : 'creation type conge',
                "Type {$type->label}",
            );

            return [
                'ok' => true,
                'type' => $this->leaveTypeRow($type->refresh()),
                'types' => $this->leaveTypeRows(),
            ];
        });
    }

    public function toggleLeaveTypeVisibility(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $siteId = $this->resolveSiteId($actor, $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null));
            $this->requireTypeManagement($actor, $siteId);

            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                $this->fail("Type d'absence requis", 400, 'leave_type_required');
            }

            $type = CrmLeaveTypeSetting::query()->lockForUpdate()->find($id);
            if (! $type) {
                $this->fail("Type d'absence introuvable", 404, 'leave_type_not_found');
            }

            $active = array_key_exists('active', $data)
                ? $this->booleanValue($data['active'])
                : ! (bool) $type->active;

            if (! $active && $this->activeLeaveTypeCount((int) $type->id) <= 0) {
                $this->fail('Gardez au moins un type visible', 422, 'last_visible_leave_type');
            }

            $type->forceFill(['active' => $active])->save();

            $this->activity->log(
                $actor,
                $active ? 'affichage type conge' : 'masquage type conge',
                "Type {$type->label}",
            );

            return [
                'ok' => true,
                'type' => $this->leaveTypeRow($type->refresh()),
                'types' => $this->leaveTypeRows(),
            ];
        });
    }

    public function deleteLeaveType(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $siteId = $this->resolveSiteId($actor, $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null));
            $this->requireTypeManagement($actor, $siteId);

            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                $this->fail("Type d'absence requis", 400, 'leave_type_required');
            }

            $type = CrmLeaveTypeSetting::query()->lockForUpdate()->find($id);
            if (! $type) {
                $this->fail("Type d'absence introuvable", 404, 'leave_type_not_found');
            }

            if ((bool) $type->is_system || $this->leaveTypeUsageCount((string) $type->value) > 0) {
                $this->fail(
                    'Ce type est protege ou utilise. Masquez-le pour le retirer des nouveaux choix.',
                    409,
                    'leave_type_not_deletable',
                );
            }

            $label = (string) $type->label;
            $type->delete();

            $this->activity->log($actor, 'suppression type conge', "Type {$label}");

            return ['ok' => true, 'deleted' => true, 'types' => $this->leaveTypeRows()];
        });
    }

    private function reviewLeave(CrmUser $actor, array $data, CrmLeaveStatus $status): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data, $status): array {
            $id = (int) ($data['id'] ?? 0);
            $siteId = $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null);

            if ($id <= 0) {
                $this->fail('Conge requis', 400);
            }

            $entry = CrmLeaveEntry::query()->lockForUpdate()->find($id);

            if (! $entry) {
                $this->fail('Conge introuvable', 404);
            }

            $employee = CrmLeaveEmployee::query()->lockForUpdate()->find((int) $entry->employee_id);
            if (! $employee) {
                $this->fail('Utilisateur HUB introuvable', 404);
            }

            $selectedSiteId = $this->requireEmployeeSiteAccess($actor, $employee, $siteId);
            $this->requireSitePermission($actor, $selectedSiteId, 'conges.manage');

            if ($status !== CrmLeaveStatus::Refused && $this->conflicts->leaveOverlaps(
                (int) $entry->employee_id,
                $this->dateAttributeToString($entry->start_date),
                $this->dateAttributeToString($entry->end_date),
                (string) $entry->period,
                (int) $entry->id,
            )) {
                $this->fail('Un conge existe deja sur cette periode', 409, 'leave_overlap');
            }

            if (
                $status === CrmLeaveStatus::Approved
                && (bool) config('crm.leaves.enforce_balances', false)
                && $this->leaveTypeConsumesBalance((string) $entry->type)
                && ! $this->balances->canRequest(
                    (int) $entry->employee_id,
                    (string) $entry->type,
                    CarbonImmutable::parse((string) $entry->start_date)->year,
                    (float) $entry->duration_days,
                    (int) $entry->id,
                )
            ) {
                $this->fail('Solde insuffisant pour ce conge', 422, 'insufficient_balance');
            }

            $oldAttributes = $entry->only([
                'employee_id',
                'start_date',
                'end_date',
                'type',
                'period',
                'duration_days',
                'status',
            ]);

            $entry->fill([
                'status' => $status->value,
                'notes' => array_key_exists('notes', $data) ? trim((string) $data['notes']) : $entry->notes,
                'updated_by' => $actor->id,
            ]);
            $entry->save();
            $this->balances->recordSavedEntry($entry, $actor, $oldAttributes);

            $action = $status === CrmLeaveStatus::Approved ? 'validation conge' : 'refus conge';
            $this->activity->log($actor, $action, "Conge #{$entry->id} - {$employee->name}");

            return ['ok' => true, 'leave' => $this->entryRow($entry->refresh()->load('employee'))];
        });
    }

    private function syncEmployeesForSite(int $siteId)
    {
        return $this->syncEmployeesForSites([$siteId]);
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return Collection<int, CrmLeaveEmployee>
     */
    private function syncEmployeesForSites(array $siteIds): Collection
    {
        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
        $users = CrmUser::query()
            ->with(['sites' => fn ($query) => $query->whereIn('crm_sites.id', $siteIds)])
            ->where('active', true)
            ->whereHas('sites', fn ($query) => $query->whereIn('crm_sites.id', $siteIds))
            ->orderBy('name')
            ->get();

        return DB::transaction(function () use ($users) {
            return $users->values()->map(function (CrmUser $user, int $index): CrmLeaveEmployee {
                return $this->syncEmployeeForUser($user, ($index + 1) * 10);
            });
        });
    }

    private function syncEmployeeForUser(CrmUser $user, int $sortOrder): CrmLeaveEmployee
    {
        $employee = CrmLeaveEmployee::query()
            ->where('crm_user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if (! $employee) {
            $employee = $this->legacyEmployeeForUser($user) ?? new CrmLeaveEmployee;
        }

        $displayName = $this->displayNameForUser($user);

        $employee->fill([
            'crm_user_id' => $user->id,
            'name' => $displayName,
            'slug' => $employee->exists
                ? $employee->slug
                : $this->uniqueEmployeeSlug($this->slugCandidateForUser($user, $displayName)),
            'color' => $employee->color ?: $this->colorForUser($user),
            'active' => true,
            'sort_order' => $employee->exists ? (int) $employee->sort_order : $sortOrder,
        ]);
        $employee->save();

        return $employee->refresh();
    }

    private function legacyEmployeeForUser(CrmUser $user): ?CrmLeaveEmployee
    {
        $slugs = collect([
            $user->name,
            $user->first_name,
            trim((string) $user->first_name.' '.(string) $user->last_name),
        ])
            ->filter(fn ($value): bool => trim((string) $value) !== '')
            ->flatMap(function ($value): array {
                $slug = Str::slug((string) $value);
                $firstPart = Str::slug(strtok((string) $value, ' ') ?: (string) $value);

                return [$slug, $firstPart];
            })
            ->flatMap(function (string $slug): array {
                $aliases = [
                    'christophe-l' => 'christophe',
                    'j-philippe' => 'jean-philippe',
                    'jean-philippe' => 'jean-philippe',
                    'jeremy-l' => 'jeremy',
                    'philippe-p' => 'philippe',
                    'remi-g' => 'remi',
                    'samy' => 'sami',
                    'samy-i' => 'sami',
                ];

                return [$slug, $aliases[$slug] ?? $slug];
            })
            ->filter()
            ->unique()
            ->values();

        if ($slugs->isEmpty()) {
            return null;
        }

        return CrmLeaveEmployee::query()
            ->whereIn('slug', $slugs->all())
            ->where(function ($query) use ($user): void {
                $query->whereNull('crm_user_id')
                    ->orWhere('crm_user_id', $user->id);
            })
            ->orderByRaw('crm_user_id IS NULL')
            ->lockForUpdate()
            ->first();
    }

    private function requireEmployeeSiteAccess(CrmUser $actor, CrmLeaveEmployee $employee, ?int $siteId): int
    {
        $selectedSiteId = $this->resolveSiteId($actor, $siteId);
        $crmUserId = (int) $employee->crm_user_id;

        if ($crmUserId <= 0) {
            $this->fail('Utilisateur non lie a un compte HUB', 403);
        }

        $exists = CrmUser::query()
            ->whereKey($crmUserId)
            ->where('active', true)
            ->whereHas('sites', fn ($query) => $query->where('crm_sites.id', $selectedSiteId))
            ->exists();

        if (! $exists) {
            $this->fail('Utilisateur non autorise sur ce site', 403);
        }

        return $selectedSiteId;
    }

    private function resolveSiteId(CrmUser $actor, ?int $siteId): int
    {
        $siteIds = $this->siteIds($actor);

        if ($siteIds === []) {
            $this->fail('Aucun site autorise', 403);
        }

        $selectedSiteId = $siteId && $siteId > 0 ? $siteId : $siteIds[0];

        if (! in_array($selectedSiteId, $siteIds, true)) {
            $this->fail('Site non autorise', 403);
        }

        $siteExists = CrmSite::query()
            ->active()
            ->whereKey($selectedSiteId)
            ->exists();

        if (! $siteExists) {
            $this->fail('Site introuvable', 404);
        }

        return $selectedSiteId;
    }

    private function availableSites(CrmUser $actor)
    {
        return CrmSite::query()
            ->active()
            ->whereIn('id', $this->siteIds($actor))
            ->orderedForHub()
            ->get();
    }

    /**
     * @return array<int, int>
     */
    private function siteIds(CrmUser $user): array
    {
        return $this->access->siteIdsForModule($user, self::MODULE, self::VIEW_PERMISSIONS);
    }

    /**
     * @return array<int, int>
     */
    private function exportSiteIdsForActor(CrmUser $user): array
    {
        return $this->access->siteIdsForModule($user, self::MODULE, self::EXPORT_PERMISSIONS);
    }

    private function requireModule(CrmUser $actor): void
    {
        if (! $this->access->hasModule($actor, self::MODULE)) {
            $this->fail('Module non autorise : conges', 403);
        }
    }

    private function requireSitePermission(CrmUser $actor, int $siteId, string $permission): void
    {
        if (! $this->canOnSite($actor, $siteId, $permission)) {
            $this->fail('Droit insuffisant : '.$permission, 403);
        }
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function requireAnySitePermission(CrmUser $actor, int $siteId, array $permissions): void
    {
        if ($this->hasAnySitePermission($actor, $siteId, $permissions)) {
            return;
        }

        $this->fail('Droit insuffisant : conges.view', 403);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function hasAnySitePermission(CrmUser $actor, int $siteId, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->canOnSite($actor, $siteId, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function requireTypeManagement(CrmUser $actor, int $siteId): void
    {
        if (! $this->canManageTypes($actor, $siteId)) {
            $this->fail('Droit insuffisant : conges.manage_types', 403);
        }
    }

    private function canOnSite(CrmUser $actor, int $siteId, string $permission): bool
    {
        return $this->access->canOnSite($actor, $siteId, self::MODULE, $permission);
    }

    private function canManageTypes(CrmUser $actor, int $siteId): bool
    {
        foreach (self::TYPE_MANAGEMENT_PERMISSIONS as $permission) {
            if ($this->canOnSite($actor, $siteId, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function isEmployeeLinkedToActor(CrmLeaveEmployee $employee, CrmUser $actor): bool
    {
        return (int) $employee->crm_user_id === (int) $actor->id;
    }

    /**
     * @return array<int, int>
     */
    private function exportSiteIds(CrmUser $actor, ?int $siteId, bool $includeOtherSites): array
    {
        $selectedSiteId = $this->resolveSiteId($actor, $siteId);
        $this->requireAnySitePermission($actor, $selectedSiteId, self::EXPORT_PERMISSIONS);

        if (! $includeOtherSites) {
            return [$selectedSiteId];
        }

        if (! $this->canIncludeOtherSites($actor, $selectedSiteId)) {
            $this->fail('Droit insuffisant pour exporter les autres sites', 403, 'cannot_export_other_sites');
        }

        return $this->exportSiteIdsForActor($actor);
    }

    private function canIncludeOtherSites(CrmUser $actor, int $siteId): bool
    {
        return $this->canOnSite($actor, $siteId, 'conges.manage') && count($this->exportSiteIdsForActor($actor)) > 1;
    }

    /**
     * @param  Collection<int, CrmLeaveEmployee>  $employees
     * @return Collection<int, CrmLeaveEmployee>
     */
    private function exportableEmployees(CrmUser $actor, int $selectedSiteId, Collection $employees): Collection
    {
        if ($this->canOnSite($actor, $selectedSiteId, 'conges.manage')) {
            return $employees->values();
        }

        return $employees
            ->filter(fn (CrmLeaveEmployee $employee): bool => $this->isEmployeeLinkedToActor($employee, $actor))
            ->values();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function exportDateRange(array $data): array
    {
        $fromDate = $this->date((string) ($data['fromDate'] ?? $data['from'] ?? $data['startDate'] ?? ''), 'debut');
        $toDate = $this->date((string) ($data['toDate'] ?? $data['to'] ?? $data['endDate'] ?? ''), 'fin');
        $from = CarbonImmutable::parse($fromDate);
        $to = CarbonImmutable::parse($toDate);

        if ($to < $from) {
            $this->fail('La date de fin doit etre apres le debut', 400);
        }

        if ($from->diffInDays($to) > 730) {
            $this->fail('Periode export trop longue', 400, 'export_period_too_long');
        }

        return [$from, $to];
    }

    /**
     * @return array<int, int>
     */
    private function integerList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function booleanValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<int, array{id:int, value:string, label:string, color:string, active:bool, requiresBalance:bool, requiresApproval:bool, sendReminders:bool, isSystem:bool, sortOrder:int, usageCount:int, canDelete:bool}>
     */
    private function leaveTypeRows(): array
    {
        $this->ensureLeaveTypeDefaults();

        return CrmLeaveTypeSetting::query()
            ->ordered()
            ->get()
            ->map(fn (CrmLeaveTypeSetting $type): array => $this->leaveTypeRow($type))
            ->values()
            ->all();
    }

    /**
     * @return array{id:int, value:string, label:string, color:string, active:bool, requiresBalance:bool, requiresApproval:bool, sendReminders:bool, isSystem:bool, sortOrder:int, usageCount:int, canDelete:bool}
     */
    private function leaveTypeRow(CrmLeaveTypeSetting $type): array
    {
        $usageCount = $this->leaveTypeUsageCount((string) $type->value);

        return [
            'id' => (int) $type->id,
            'value' => (string) $type->value,
            'label' => (string) $type->label,
            'color' => $this->leaveTypeColor((string) $type->color),
            'active' => (bool) $type->active,
            'requiresBalance' => (bool) $type->requires_balance,
            'requiresApproval' => (bool) $type->requires_approval,
            'sendReminders' => (bool) $type->send_reminders,
            'isSystem' => (bool) $type->is_system,
            'sortOrder' => (int) $type->sort_order,
            'usageCount' => $usageCount,
            'canDelete' => ! (bool) $type->is_system && $usageCount === 0,
        ];
    }

    private function leaveTypeValue(string $value, ?string $currentValue = null): string
    {
        $value = trim($value) ?: CrmLeaveType::PaidLeave->value;
        $allValues = $this->leaveTypeValues(includeInactive: true);

        if (! in_array($value, $allValues, true)) {
            $this->fail("Type d'absence introuvable", 422, 'leave_type_unknown');
        }

        if ($value === $currentValue) {
            return $value;
        }

        if (! in_array($value, $this->leaveTypeValues(), true)) {
            $this->fail("Ce type d'absence est masque", 422, 'leave_type_hidden');
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function leaveTypeValues(bool $includeInactive = false): array
    {
        $this->ensureLeaveTypeDefaults();

        return CrmLeaveTypeSetting::query()
            ->when(! $includeInactive, fn ($query) => $query->where('active', true))
            ->ordered()
            ->pluck('value')
            ->map(fn ($value): string => (string) $value)
            ->all();
    }

    private function leaveTypeConsumesBalance(string $value): bool
    {
        $this->ensureLeaveTypeDefaults();

        return (bool) CrmLeaveTypeSetting::query()
            ->where('value', $value)
            ->value('requires_balance');
    }

    private function leaveTypeRequiresApproval(string $value): bool
    {
        return $value === CrmLeaveType::PaidLeave->value;
    }

    private function leaveTypeUsageCount(string $value): int
    {
        return CrmLeaveEntry::query()
            ->where('type', $value)
            ->count();
    }

    private function activeLeaveTypeCount(?int $ignoreId = null): int
    {
        $this->ensureLeaveTypeDefaults();

        return CrmLeaveTypeSetting::query()
            ->where('active', true)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->count();
    }

    private function nextLeaveTypeSortOrder(): int
    {
        $this->ensureLeaveTypeDefaults();

        return ((int) CrmLeaveTypeSetting::query()->max('sort_order')) + 10;
    }

    private function uniqueLeaveTypeValue(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'type_absence';
        if (! preg_match('/^[a-z]/', $base)) {
            $base = 'type_'.$base;
        }

        $value = Str::limit($base, 36, '');
        $suffix = 2;

        while (CrmLeaveTypeSetting::query()->where('value', $value)->exists()) {
            $candidate = Str::limit($base, 32, '');
            $value = "{$candidate}_{$suffix}";
            $suffix++;
        }

        return $value;
    }

    private function leaveTypeColor(string $value): string
    {
        $value = trim($value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : '#38bdf8';
    }

    private function ensureLeaveTypeDefaults(): void
    {
        if (! Schema::hasTable('crm_leave_types')) {
            return;
        }

        $defaults = [
            [CrmLeaveType::PaidLeave->value, CrmLeaveType::PaidLeave->label(), '#facc15', true, true, true, true, 10],
            [CrmLeaveType::Rtt->value, CrmLeaveType::Rtt->label(), '#38bdf8', true, false, true, true, 20],
            [CrmLeaveType::Absence->value, CrmLeaveType::Absence->label(), '#fb7185', false, false, true, true, 30],
            [CrmLeaveType::Training->value, CrmLeaveType::Training->label(), '#a78bfa', false, false, false, true, 40],
            [CrmLeaveType::SickLeave->value, CrmLeaveType::SickLeave->label(), '#94a3b8', false, false, true, true, 50],
        ];

        foreach ($defaults as [$value, $label, $color, $requiresBalance, $requiresApproval, $sendReminders, $isSystem, $sortOrder]) {
            $type = CrmLeaveTypeSetting::query()->firstOrCreate(
                ['value' => $value],
                [
                    'label' => $label,
                    'color' => $color,
                    'active' => true,
                    'requires_balance' => $requiresBalance,
                    'requires_approval' => $requiresApproval,
                    'send_reminders' => $sendReminders,
                    'is_system' => $isSystem,
                    'sort_order' => $sortOrder,
                ],
            );

            if ((bool) $type->requires_approval !== $requiresApproval) {
                $type->forceFill(['requires_approval' => $requiresApproval])->save();
            }
        }
    }

    private function uniqueEmployeeSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'utilisateur';
        $slug = $base;
        $suffix = 2;

        while (CrmLeaveEmployee::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function displayNameForUser(CrmUser $user): string
    {
        $name = trim((string) $user->name);

        if ($name !== '') {
            return $name;
        }

        $name = trim((string) $user->first_name.' '.(string) $user->last_name);

        return $name !== '' ? $name : 'Utilisateur #'.$user->id;
    }

    private function slugCandidateForUser(CrmUser $user, string $displayName): string
    {
        $name = trim((string) $user->name);

        if ($name !== '') {
            return $name;
        }

        return $displayName;
    }

    private function colorForUser(CrmUser $user): string
    {
        $palette = [
            '#2563eb',
            '#16a34a',
            '#64748b',
            '#dc2626',
            '#9333ea',
            '#f59e0b',
            '#0891b2',
            '#be123c',
        ];

        return $palette[max(0, ((int) $user->id - 1) % count($palette))];
    }

    private function siteRow(CrmSite $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'slug' => $site->slug,
            'active' => (bool) $site->active,
            'address' => trim((string) $site->address),
            'phone' => trim((string) $site->phone),
            'email' => trim((string) $site->email),
            'color' => $this->siteColor((string) $site->color),
        ];
    }

    private function siteColor(string $value): string
    {
        $value = trim($value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : CrmTheme::primaryHex();
    }

    private function employeeRow(CrmLeaveEmployee $employee): array
    {
        return [
            'id' => $employee->id,
            'crmUserId' => $employee->crm_user_id,
            'name' => $employee->name,
            'slug' => $employee->slug,
            'color' => $employee->color ?: '#f59e0b',
            'photoUrl' => $this->employeePhotoUrl($employee),
            'active' => (bool) $employee->active,
            'sortOrder' => (int) $employee->sort_order,
        ];
    }

    private function employeePhotoUrl(CrmLeaveEmployee $employee): string
    {
        $crmUserId = (int) $employee->getAttribute('crm_user_id');

        if ($crmUserId <= 0) {
            return '/assets/logo/logomark.png';
        }

        $photoUrl = CrmUser::query()
            ->whereKey($crmUserId)
            ->value('photo_url');

        return $this->images->normalizePublicUrl(is_string($photoUrl) ? $photoUrl : null) ?: '/assets/logo/logomark.png';
    }

    /**
     * @param  array<int, int>  $siteIds
     * @param  Collection<int, CrmSite>  $sites
     */
    private function exportEmployeeRow(CrmLeaveEmployee $employee, array $siteIds, Collection $sites): array
    {
        $crmUser = CrmUser::query()
            ->with('sites:id,name')
            ->whereKey((int) $employee->getAttribute('crm_user_id'))
            ->first();
        $userSiteIds = $crmUser
            ? $crmUser->sites
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->intersect($siteIds)
                ->values()
                ->all()
            : [];
        $siteNames = collect($userSiteIds)
            ->map(function (int $id) use ($sites): string {
                $site = $sites->get($id);

                return $site instanceof CrmSite ? (string) $site->getAttribute('name') : '';
            })
            ->filter()
            ->values()
            ->all();

        return [
            ...$this->employeeRow($employee),
            'siteIds' => $userSiteIds,
            'siteNames' => $siteNames,
        ];
    }

    /**
     * @return array{id:int, name:string, color:string}
     */
    private function exportEmployeePdfRow(CrmLeaveEmployee $employee): array
    {
        return [
            'id' => (int) $employee->getAttribute('id'),
            'name' => (string) $employee->getAttribute('name'),
            'color' => (string) ($employee->getAttribute('color') ?: '#f59e0b'),
        ];
    }

    /**
     * @return array{employee_id:int, start_date:string, end_date:string, type:string, period:string, status:string}
     */
    private function exportEntryPdfRow(CrmLeaveEntry $entry): array
    {
        return [
            'employee_id' => (int) $entry->getAttribute('employee_id'),
            'start_date' => $this->dateAttributeToString($entry->getAttribute('start_date')),
            'end_date' => $this->dateAttributeToString($entry->getAttribute('end_date')),
            'type' => (string) ($entry->getAttribute('type') ?: CrmLeaveType::PaidLeave->value),
            'period' => (string) ($entry->getAttribute('period') ?: CrmLeavePeriod::Full->value),
            'status' => (string) ($entry->getAttribute('status') ?: CrmLeaveStatus::Approved->value),
        ];
    }

    private function dateAttributeToString(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return (string) $value;
    }

    private function entryRow(CrmLeaveEntry $entry): array
    {
        $employee = $entry->relationLoaded('employee') ? $entry->employee : $entry->employee()->first();

        return [
            'id' => $entry->id,
            'employeeId' => $entry->employee_id,
            'employeeName' => $employee?->name ?? '',
            'employeeColor' => $employee?->color ?? '#f59e0b',
            'startDate' => $entry->start_date?->toDateString(),
            'endDate' => $entry->end_date?->toDateString(),
            'type' => $entry->type ?: 'conge',
            'period' => $entry->period ?: 'full',
            'durationDays' => (float) $entry->duration_days,
            'status' => $entry->status ?: 'approved',
            'notes' => $entry->notes ?? '',
            'source' => $entry->source ?? 'crm',
            'createdBy' => $entry->created_by ? (int) $entry->created_by : null,
            'updatedBy' => $entry->updated_by ? (int) $entry->updated_by : null,
        ];
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function date(string $value, string $field): string
    {
        $value = trim($value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $this->fail('Date invalide : '.$field, 400);
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (! checkdate($month, $day, $year)) {
            $this->fail('Date invalide : '.$field, 400);
        }

        return $value;
    }

    private function color(string $value): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', trim($value))
            ? strtolower(trim($value))
            : '#f59e0b';
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function choice(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function fail(string $message, int $status, ?string $code = null): never
    {
        if ($code !== null) {
            throw new LeaveApiException($message, $status, $code);
        }

        throw new HttpException($status, $message);
    }
}
