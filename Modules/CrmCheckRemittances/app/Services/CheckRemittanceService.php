<?php

namespace Modules\CrmCheckRemittances\Services;

use App\Models\CrmCheckRemittance;
use App\Models\CrmCheckRemittanceLine;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CrmCore\Services\CrmAccessService;
use Modules\CrmCore\Services\CrmActivityLogger;
use Modules\CrmCore\Services\CrmImageStorage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CheckRemittanceService
{
    private const MODULE = 'remise-cheques';

    private const VIEW_PERMISSION = 'check_remittances.view';

    private const MANAGE_PERMISSION = 'check_remittances.manage';

    public function __construct(
        private readonly CrmActivityLogger $activity,
        private readonly CrmAccessService $access,
        private readonly CheckImageOcrService $checkOcr,
        private readonly CrmImageStorage $images,
    ) {}

    public function actorForUser(User $user): CrmUser
    {
        $actor = CrmUser::query()
            ->with(['modules:id,slug,active', 'permissions:id,name,label,sort_order', 'sites:id'])
            ->where('user_id', $user->id)
            ->where('active', true)
            ->first();

        if (! $actor) {
            $this->fail('Utilisateur HUB introuvable', 404);
        }

        return $actor;
    }

    public function bootstrap(CrmUser $actor, ?int $siteId = null, array $filters = []): array
    {
        $this->requireModule($actor);

        $selectedSiteId = $this->resolveSiteId($actor, $siteId);
        $this->requireSitePermission($actor, $selectedSiteId, self::VIEW_PERMISSION);

        $limit = $this->limit($filters['limit'] ?? 10);
        $year = $this->optionalPositiveInt($filters['year'] ?? null);
        $month = $this->optionalPositiveInt($filters['month'] ?? null);

        if ($month !== null && ($month < 1 || $month > 12)) {
            $this->fail('Mois invalide', 422);
        }

        $query = CrmCheckRemittance::query()
            ->with(['checks', 'site:id,name'])
            ->forSite($selectedSiteId)
            ->when($year, fn ($query) => $query->whereYear('remittance_date', $year))
            ->when($month, fn ($query) => $query->whereMonth('remittance_date', $month))
            ->orderByDesc('remittance_date')
            ->orderByDesc('id')
            ->limit($limit);

        $remittances = $query->get();

        return [
            'ok' => true,
            'user' => [
                'id' => $actor->id,
                'name' => $actor->name,
                'role' => $actor->role,
                'permissions' => $this->access->permissionNames($actor),
                'siteIds' => $this->siteIds($actor),
                'selectedSiteId' => $selectedSiteId,
                'canManage' => $this->canOnSite($actor, $selectedSiteId, self::MANAGE_PERMISSION),
            ],
            'sites' => $this->availableSites($actor)
                ->map(fn (CrmSite $site): array => $this->siteRow($site))
                ->values()
                ->all(),
            'selectedSiteId' => $selectedSiteId,
            'filters' => [
                'limit' => $limit,
                'year' => $year,
                'month' => $month,
            ],
            'summary' => $this->summary($selectedSiteId, $year, $month),
            'remittances' => $remittances
                ->map(fn (CrmCheckRemittance $remittance): array => $this->remittanceRow($remittance))
                ->values()
                ->all(),
            'statusOptions' => $this->statusRows(),
        ];
    }

    public function saveRemittance(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = max(0, (int) ($data['id'] ?? 0));
            $siteId = $this->resolveSiteId($actor, $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null));
            $this->requireSitePermission($actor, $siteId, self::MANAGE_PERMISSION);

            $remittance = $id > 0
                ? CrmCheckRemittance::query()->with('checks')->lockForUpdate()->find($id)
                : new CrmCheckRemittance;

            if ($id > 0 && ! $remittance) {
                $this->fail('Remise introuvable', 404);
            }

            if ($id > 0) {
                $this->requireSitePermission($actor, (int) $remittance->site_id, self::MANAGE_PERMISSION);
            }

            $date = $this->date((string) ($data['remittanceDate'] ?? $data['remittance_date'] ?? $data['date'] ?? now()->format('Y-m-d')), 'date de remise');
            $status = (string) ($data['status'] ?? $remittance->status ?? CrmCheckRemittance::STATUS_DRAFT);
            if (! in_array($status, array_keys($this->statusLabels()), true)) {
                $status = CrmCheckRemittance::STATUS_DRAFT;
            }

            $remittance->fill([
                'site_id' => $siteId,
                'remittance_date' => $date,
                'reference' => $this->limitedText($data['reference'] ?? $remittance->reference ?? $this->nextReference($siteId, $date), 80, 'Reference'),
                'bank_name' => $this->limitedText($data['bankName'] ?? $data['bank_name'] ?? $remittance->bank_name ?? '', 120, 'Banque'),
                'status' => $status,
                'notes' => $this->limitedText($data['notes'] ?? $remittance->notes ?? '', 4000, 'Notes'),
                'updated_by' => $actor->id,
            ]);

            if (! $remittance->exists) {
                $remittance->created_by = $actor->id;
            }

            $remittance->save();
            $remittance = $this->recalculate($remittance->refresh()->load('checks'));
            $this->activity->log($actor, $id > 0 ? 'modification remise cheques' : 'creation remise cheques', $remittance->reference ?: ('Remise #'.$remittance->id));

            return ['ok' => true, 'remittance' => $this->remittanceRow($remittance)];
        });
    }

    public function showRemittance(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        $id = (int) ($data['id'] ?? $data['remittanceId'] ?? $data['remittance_id'] ?? 0);
        if ($id <= 0) {
            $this->fail('Remise requise', 400);
        }

        $remittance = CrmCheckRemittance::query()
            ->with(['checks', 'site:id,name'])
            ->find($id);

        if (! $remittance) {
            $this->fail('Remise introuvable', 404);
        }

        $this->requireSitePermission($actor, (int) $remittance->site_id, self::VIEW_PERMISSION);

        return [
            'ok' => true,
            'remittance' => $this->remittanceRow($remittance),
            'canManage' => $this->canOnSite($actor, (int) $remittance->site_id, self::MANAGE_PERMISSION),
        ];
    }

    public function saveCheck(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $remittanceId = (int) ($data['remittanceId'] ?? $data['remittance_id'] ?? 0);
            if ($remittanceId <= 0) {
                $this->fail('Remise requise', 400);
            }

            $remittance = CrmCheckRemittance::query()
                ->with('checks')
                ->lockForUpdate()
                ->find($remittanceId);

            if (! $remittance) {
                $this->fail('Remise introuvable', 404);
            }

            $this->requireSitePermission($actor, (int) $remittance->site_id, self::MANAGE_PERMISSION);

            $id = max(0, (int) ($data['id'] ?? 0));
            $check = $id > 0
                ? CrmCheckRemittanceLine::query()->lockForUpdate()->where('check_remittance_id', $remittance->id)->find($id)
                : new CrmCheckRemittanceLine;

            if ($id > 0 && ! $check) {
                $this->fail('Cheque introuvable', 404);
            }

            $amount = $this->positiveDecimal($data['amount'] ?? 0, 'montant');
            $payerName = $this->limitedText($data['payerName'] ?? $data['payer_name'] ?? '', 190, 'Nom');
            $invoiceNumber = $this->limitedText($data['invoiceNumber'] ?? $data['invoice_number'] ?? '', 80, 'Numero facture');

            if ($payerName === '') {
                $this->fail('Nom requis', 422);
            }

            if ($invoiceNumber === '') {
                $this->fail('Numero facture requis', 422);
            }

            $check->fill([
                'check_remittance_id' => $remittance->id,
                'payer_name' => $payerName,
                'invoice_number' => $invoiceNumber,
                'check_number' => $this->limitedText($data['checkNumber'] ?? $data['check_number'] ?? '', 80, 'Numero cheque'),
                'bank_name' => $this->limitedText($data['bankName'] ?? $data['bank_name'] ?? '', 120, 'Banque'),
                'check_date' => $this->nullableDate((string) ($data['checkDate'] ?? $data['check_date'] ?? ''), 'date cheque'),
                'amount' => $amount,
                'ocr_text' => $this->limitedText($data['ocrText'] ?? $data['ocr_text'] ?? '', 20000, 'OCR'),
                'ocr_confidence' => $this->nullableDecimal($data['ocrConfidence'] ?? $data['ocr_confidence'] ?? null),
                'sort_order' => (int) ($data['sortOrder'] ?? $data['sort_order'] ?? ($check->sort_order ?: 100)),
                'updated_by' => $actor->id,
            ]);

            if (! $check->exists) {
                $check->created_by = $actor->id;
            }

            $photoDataUrl = trim((string) ($data['photoDataUrl'] ?? $data['photo_data_url'] ?? ''));
            if ($photoDataUrl !== '') {
                $check->fill($this->storePhoto(
                    $remittance,
                    $photoDataUrl,
                    (string) ($data['photoName'] ?? $data['originalName'] ?? $data['original_name'] ?? ''),
                    $check->getAttribute('photo_path'),
                ));
            }

            $check->save();

            $remittance = $this->recalculate($remittance->refresh()->load('checks'));
            $this->activity->log($actor, $id > 0 ? 'modification cheque remise' : 'ajout cheque remise', "Remise #{$remittance->id} - cheque #{$check->id}");

            return [
                'ok' => true,
                'check' => $this->checkRow($check->refresh()),
                'remittance' => $this->remittanceRow($remittance),
            ];
        });
    }

    public function deleteCheck(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                $this->fail('Cheque requis', 400);
            }

            $check = CrmCheckRemittanceLine::query()
                ->with('remittance')
                ->lockForUpdate()
                ->find($id);

            if (! $check || ! $check->remittance) {
                $this->fail('Cheque introuvable', 404);
            }

            $remittance = $check->remittance;
            $this->requireSitePermission($actor, (int) $remittance->site_id, self::MANAGE_PERMISSION);
            $check->delete();
            $remittance = $this->recalculate($remittance->refresh()->load('checks'));

            $this->activity->log($actor, 'suppression cheque remise', "Remise #{$remittance->id} - cheque #{$id}");

            return ['ok' => true, 'deleted' => true, 'remittance' => $this->remittanceRow($remittance)];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function detectCheckOcr(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        $remittanceId = (int) ($data['remittanceId'] ?? $data['remittance_id'] ?? 0);
        if ($remittanceId > 0) {
            $remittance = CrmCheckRemittance::query()->find($remittanceId);
            if (! $remittance) {
                $this->fail('Remise introuvable', 404);
            }

            $this->requireSitePermission($actor, (int) $remittance->site_id, self::MANAGE_PERMISSION);
        } else {
            $siteId = $this->resolveSiteId($actor, $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null));
            $this->requireSitePermission($actor, $siteId, self::MANAGE_PERMISSION);
        }

        return [
            'ok' => true,
            ...$this->checkOcr->detect($data),
        ];
    }

    public function deleteRemittance(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                $this->fail('Remise requise', 400);
            }

            $remittance = CrmCheckRemittance::query()->lockForUpdate()->find($id);
            if (! $remittance) {
                $this->fail('Remise introuvable', 404);
            }

            $this->requireSitePermission($actor, (int) $remittance->site_id, self::MANAGE_PERMISSION);
            $reference = $remittance->reference ?: ('Remise #'.$remittance->id);
            CrmCheckRemittanceLine::query()
                ->where('check_remittance_id', $remittance->id)
                ->delete();
            $remittance->delete();

            $this->activity->log($actor, 'suppression remise cheques', $reference);

            return ['ok' => true, 'deleted' => true, 'id' => $id];
        });
    }

    private function recalculate(CrmCheckRemittance $remittance): CrmCheckRemittance
    {
        $remittance->loadMissing('checks');
        $checks = $remittance->checks;

        $remittance->forceFill([
            'check_count' => $checks->count(),
            'total_amount' => $this->money($checks->sum(fn (CrmCheckRemittanceLine $line): float => (float) $line->amount)),
        ])->save();

        return $remittance->refresh()->load('checks');
    }

    private function summary(int $siteId, ?int $year, ?int $month): array
    {
        $query = CrmCheckRemittance::query()
            ->forSite($siteId)
            ->when($year, fn ($query) => $query->whereYear('remittance_date', $year))
            ->when($month, fn ($query) => $query->whereMonth('remittance_date', $month));

        return [
            'remittanceCount' => (clone $query)->count(),
            'checkCount' => (int) (clone $query)->sum('check_count'),
            'totalAmount' => $this->money((float) (clone $query)->sum('total_amount')),
        ];
    }

    private function remittanceRow(CrmCheckRemittance $remittance): array
    {
        $remittance->loadMissing(['checks', 'site:id,name']);

        return [
            'id' => $remittance->id,
            'siteId' => (int) $remittance->site_id,
            'siteName' => $remittance->site?->name ?? '',
            'remittanceDate' => $remittance->remittance_date?->format('Y-m-d'),
            'reference' => $remittance->reference ?? '',
            'bankName' => $remittance->bank_name ?? '',
            'status' => $remittance->status ?: CrmCheckRemittance::STATUS_DRAFT,
            'statusLabel' => $this->statusLabels()[$remittance->status] ?? 'Brouillon',
            'checkCount' => (int) $remittance->check_count,
            'totalAmount' => $this->money($remittance->total_amount),
            'notes' => $remittance->notes ?? '',
            'checks' => $remittance->checks->map(fn (CrmCheckRemittanceLine $line): array => $this->checkRow($line))->values()->all(),
        ];
    }

    private function checkRow(CrmCheckRemittanceLine $check): array
    {
        return [
            'id' => $check->id,
            'remittanceId' => (int) $check->check_remittance_id,
            'payerName' => $check->payer_name ?? '',
            'invoiceNumber' => $check->invoice_number ?? '',
            'checkNumber' => $check->check_number ?? '',
            'bankName' => $check->bank_name ?? '',
            'checkDate' => $check->check_date?->format('Y-m-d') ?? '',
            'amount' => $this->money($check->amount),
            'photoPath' => $check->photo_path ?? '',
            'originalName' => $check->original_name ?? '',
            'ocrText' => $check->ocr_text ?? '',
            'ocrConfidence' => $check->ocr_confidence === null ? null : (float) $check->ocr_confidence,
            'sortOrder' => (int) $check->sort_order,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusRows(): array
    {
        return collect($this->statusLabels())
            ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            CrmCheckRemittance::STATUS_DRAFT => 'Brouillon',
            CrmCheckRemittance::STATUS_READY => 'Prête à déposer',
            CrmCheckRemittance::STATUS_DEPOSITED => 'Déposée',
        ];
    }

    private function storePhoto(CrmCheckRemittance $remittance, string $dataUrl, string $originalName, ?string $replacePath): array
    {
        if (! preg_match('/^data:(image\/(?:png|jpe?g|webp));base64,/', $dataUrl, $matches)) {
            $this->fail('Photo de cheque invalide', 400);
        }

        $date = $remittance->remittance_date?->format('Y-m-d') ?: CarbonImmutable::today()->format('Y-m-d');
        $stored = $this->images->storeDataUrl(
            $dataUrl,
            'check-remittances/site-'.$remittance->site_id.'/'.$date,
            $replacePath,
            [
                'maxBytes' => 8 * 1024 * 1024,
                'label' => 'Photo de cheque',
            ],
        );

        return [
            'photo_path' => $stored['url'],
            'original_name' => $this->safeFileName($originalName, 'cheque.webp'),
            'mime_type' => $stored['mime'],
            'size' => $stored['size'],
        ];
    }

    private function nextReference(int $siteId, string $date): string
    {
        $prefix = 'RC-'.str_replace('-', '', $date).'-';
        $count = CrmCheckRemittance::query()
            ->where('site_id', $siteId)
            ->whereDate('remittance_date', $date)
            ->count() + 1;

        return $prefix.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
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

        if (! CrmSite::query()->active()->whereKey($selectedSiteId)->exists()) {
            $this->fail('Site introuvable', 404);
        }

        return $selectedSiteId;
    }

    /**
     * @return array<int, int>
     */
    private function siteIds(CrmUser $actor): array
    {
        return $this->access->siteIdsForModule($actor, self::MODULE, [
            self::VIEW_PERMISSION,
            self::MANAGE_PERMISSION,
        ]);
    }

    private function availableSites(CrmUser $actor)
    {
        return CrmSite::query()
            ->active()
            ->whereIn('id', $this->siteIds($actor))
            ->orderBy('name')
            ->get();
    }

    private function requireModule(CrmUser $actor): void
    {
        if (! $this->access->hasModule($actor, self::MODULE)) {
            $this->fail('Module remise de cheques non autorise', 403);
        }
    }

    private function requireSitePermission(CrmUser $actor, int $siteId, string $permission): void
    {
        if (! $this->canOnSite($actor, $siteId, $permission)) {
            $this->fail('Droit insuffisant : '.$permission, 403);
        }
    }

    private function canOnSite(CrmUser $actor, int $siteId, string $permission): bool
    {
        return $this->access->canOnSite($actor, $siteId, self::MODULE, $permission);
    }

    private function siteRow(CrmSite $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'slug' => $site->slug,
            'active' => (bool) $site->active,
        ];
    }

    private function limit(mixed $value): int
    {
        $limit = (int) $value;

        return in_array($limit, [10, 20, 50, 100], true) ? $limit : 10;
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    private function date(string $value, string $label): string
    {
        try {
            return CarbonImmutable::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            $this->fail($label.' invalide', 422);
        }
    }

    private function nullableDate(string $value, string $label): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $this->date($value, $label);
    }

    private function positiveDecimal(mixed $value, string $label): float
    {
        $number = $this->decimal($value);
        if ($number <= 0) {
            $this->fail(ucfirst($label).' requis', 400);
        }

        return $number;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->decimal($value);
    }

    private function decimal(mixed $value): float
    {
        $normalized = str_replace(["\xc2\xa0", ' '], '', trim((string) $value));
        $normalized = str_replace(',', '.', $normalized);
        $number = is_numeric($normalized) ? (float) $normalized : 0.0;

        return $this->money($number);
    }

    private function money(float $value): float
    {
        return round($value + 0.00001, 2);
    }

    private function limitedText(mixed $value, int $max, string $label): string
    {
        $text = trim((string) $value);
        if (mb_strlen($text) > $max) {
            $this->fail($label.' trop long', 400);
        }

        return $text;
    }

    private function safeFileName(string $value, string $fallback): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '', $value));

        return $name === '' ? $fallback : Str::limit($name, 120, '');
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpException($status, $message);
    }
}
