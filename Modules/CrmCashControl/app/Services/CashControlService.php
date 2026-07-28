<?php

namespace Modules\CrmCashControl\Services;

use App\Models\CrmCashCountLine;
use App\Models\CrmCashMovement;
use App\Models\CrmCashReceipt;
use App\Models\CrmCashRegisterDay;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\User;
use App\Support\CrmTheme;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\CrmCore\Services\CrmAccessService;
use Modules\CrmCore\Services\CrmActivityLogger;
use Modules\CrmCore\Services\CrmImageStorage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CashControlService
{
    private const MODULE = 'controle-caisse';

    private const VIEW_PERMISSION = 'controle_caisse.view';

    private const MANAGE_PERMISSION = 'controle_caisse.manage';

    private const CASH_DENOMINATIONS = [
        ['kind' => 'bill', 'label' => 'Billet', 'value' => 500.0],
        ['kind' => 'bill', 'label' => 'Billet', 'value' => 200.0],
        ['kind' => 'bill', 'label' => 'Billet', 'value' => 100.0],
        ['kind' => 'bill', 'label' => 'Billet', 'value' => 50.0],
        ['kind' => 'bill', 'label' => 'Billet', 'value' => 20.0],
        ['kind' => 'bill', 'label' => 'Billet', 'value' => 10.0],
        ['kind' => 'bill', 'label' => 'Billet', 'value' => 5.0],
        ['kind' => 'coin', 'label' => 'Piece', 'value' => 2.0],
        ['kind' => 'coin', 'label' => 'Piece', 'value' => 1.0],
        ['kind' => 'coin', 'label' => 'Piece', 'value' => 0.5],
        ['kind' => 'coin', 'label' => 'Piece', 'value' => 0.2],
        ['kind' => 'coin', 'label' => 'Piece', 'value' => 0.1],
        ['kind' => 'coin', 'label' => 'Piece', 'value' => 0.05],
        ['kind' => 'coin', 'label' => 'Piece', 'value' => 0.02],
        ['kind' => 'coin', 'label' => 'Piece', 'value' => 0.01],
    ];

    public function __construct(
        private readonly CrmActivityLogger $activity,
        private readonly CrmAccessService $access,
        private readonly CrmImageStorage $images,
    ) {}

    public function actorForUser(User $user): CrmUser
    {
        $actor = CrmUser::query()
            ->with(['modules:id,slug,active', 'permissions:id,name,label,sort_order', 'sites:id'])
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
        $this->requireSitePermission($actor, $selectedSiteId, self::VIEW_PERMISSION);

        $days = CrmCashRegisterDay::query()
            ->with(['movements', 'receipts', 'cashCountLines'])
            ->forSite($selectedSiteId)
            ->orderByDesc('cash_date')
            ->orderByDesc('id')
            ->limit(120)
            ->get();

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
            'nextDate' => $this->nextDate($selectedSiteId),
            'days' => $days
                ->map(fn (CrmCashRegisterDay $day): array => $this->dayRow($day))
                ->values()
                ->all(),
            'statuses' => $this->statusRows(),
            'movementTypes' => collect(CrmCashMovement::typeOptions())
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ];
    }

    public function createDay(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $siteId = $this->resolveSiteId($actor, $this->optionalPositiveInt($data['siteId'] ?? $data['site_id'] ?? null));
            $this->requireSitePermission($actor, $siteId, self::MANAGE_PERMISSION);

            $cashDate = $this->date((string) ($data['cashDate'] ?? $data['cash_date'] ?? $data['date'] ?? $this->nextDate($siteId)), 'jour de caisse');

            $existing = CrmCashRegisterDay::query()
                ->with(['movements', 'receipts', 'cashCountLines'])
                ->where('site_id', $siteId)
                ->whereDate('cash_date', $cashDate)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [
                    'ok' => true,
                    'existed' => true,
                    'day' => $this->dayRow($this->recalculateAndSave($existing)),
                    'nextDate' => $this->nextDate($siteId),
                ];
            }

            $day = CrmCashRegisterDay::query()->create([
                'site_id' => $siteId,
                'cash_date' => $cashDate,
                'opening_balance' => $this->openingBalanceFromPreviousDay($siteId, $cashDate),
                'invoice_total' => 0,
                'cash_sales' => 0,
                'card_sales' => 0,
                'check_sales' => 0,
                'transfer_sales' => 0,
                'counted_cash' => null,
                'bank_counted' => null,
                'check_counted' => null,
                'transfer_counted' => null,
                'card_counted' => null,
                'invoice_errors_count' => 0,
                'status' => CrmCashRegisterDay::STATUS_REVIEW,
                'notes' => '',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $day = $this->recalculateAndSave($day->refresh()->load(['movements', 'receipts', 'cashCountLines']));
            $this->activity->log($actor, 'creation controle caisse', "Caisse {$cashDate} - site #{$siteId}");

            return [
                'ok' => true,
                'existed' => false,
                'day' => $this->dayRow($day),
                'nextDate' => $this->nextDate($siteId),
            ];
        });
    }

    public function saveDay(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);

            if ($id <= 0) {
                $this->fail('Jour de caisse requis', 400);
            }

            $day = CrmCashRegisterDay::query()
                ->with(['movements', 'receipts', 'cashCountLines'])
                ->lockForUpdate()
                ->find($id);

            if (! $day) {
                $this->fail('Jour de caisse introuvable', 404);
            }

            $this->requireSitePermission($actor, (int) $day->site_id, self::MANAGE_PERMISSION);

            $cashDate = $this->date((string) ($data['cashDate'] ?? $data['cash_date'] ?? $day->cash_date?->format('Y-m-d')), 'jour de caisse');

            $duplicate = CrmCashRegisterDay::query()
                ->where('site_id', $day->site_id)
                ->whereDate('cash_date', $cashDate)
                ->whereKeyNot($day->id)
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                $this->fail('Une caisse existe deja pour cette date', 409);
            }

            $day->fill([
                'cash_date' => $cashDate,
                'opening_balance' => $this->decimal($data['openingBalance'] ?? $data['opening_balance'] ?? $day->opening_balance),
                'invoice_total' => $this->decimal($data['invoiceTotal'] ?? $data['invoice_total'] ?? $day->invoice_total),
                'cash_sales' => $this->decimal($data['cashSales'] ?? $data['cash_sales'] ?? $day->cash_sales),
                'card_sales' => $this->decimal($data['cardSales'] ?? $data['card_sales'] ?? $day->card_sales),
                'check_sales' => $this->decimal($data['checkSales'] ?? $data['check_sales'] ?? $day->check_sales),
                'transfer_sales' => $this->decimal($data['transferSales'] ?? $data['transfer_sales'] ?? $day->transfer_sales),
                'counted_cash' => $this->nullableDecimal($data['countedCash'] ?? $data['counted_cash'] ?? $day->counted_cash),
                'bank_counted' => $this->nullableDecimal($data['bankCounted'] ?? $data['bank_counted'] ?? $day->bank_counted),
                'check_counted' => $this->nullableDecimal($data['checkCounted'] ?? $data['check_counted'] ?? $day->check_counted),
                'transfer_counted' => $this->nullableDecimal($data['transferCounted'] ?? $data['transfer_counted'] ?? $day->transfer_counted),
                'card_counted' => $this->nullableDecimal($data['cardCounted'] ?? $data['card_counted'] ?? $day->card_counted),
                'invoice_errors_count' => $this->nonNegativeInt($data['invoiceErrorsCount'] ?? $data['invoice_errors_count'] ?? $day->invoice_errors_count, 'erreurs facture'),
                'notes' => $this->limitedText($data['notes'] ?? $day->notes ?? '', 4000, 'Notes'),
                'updated_by' => $actor->id,
            ]);

            if ($day->receipts->isNotEmpty()) {
                $day = $this->syncPaymentTotalsFromReceipts($day);
            }

            $day = $this->recalculateAndSave($day);
            $this->activity->log($actor, 'modification controle caisse', "Caisse {$cashDate} - site #{$day->site_id}");

            return ['ok' => true, 'day' => $this->dayRow($day)];
        });
    }

    public function saveMovement(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $dayId = (int) ($data['dayId'] ?? $data['day_id'] ?? $data['cashRegisterDayId'] ?? 0);

            if ($dayId <= 0) {
                $this->fail('Jour de caisse requis', 400);
            }

            $day = CrmCashRegisterDay::query()
                ->with(['movements', 'receipts', 'cashCountLines'])
                ->lockForUpdate()
                ->find($dayId);

            if (! $day) {
                $this->fail('Jour de caisse introuvable', 404);
            }

            $this->requireSitePermission($actor, (int) $day->site_id, self::MANAGE_PERMISSION);

            $id = max(0, (int) ($data['id'] ?? 0));
            $movement = $id > 0
                ? CrmCashMovement::query()->lockForUpdate()->where('cash_register_day_id', $day->id)->find($id)
                : new CrmCashMovement;

            if ($id > 0 && ! $movement) {
                $this->fail('Mouvement introuvable', 404);
            }

            $type = (string) ($data['type'] ?? CrmCashMovement::TYPE_CASH_OUT);
            if (! in_array($type, array_keys(CrmCashMovement::typeOptions()), true)) {
                $this->fail('Type de mouvement invalide', 400);
            }

            $label = trim((string) ($data['label'] ?? ''));
            if ($label === '') {
                $label = $type === CrmCashMovement::TYPE_CASH_OUT ? 'Sortie caisse' : 'Entree caisse';
            }

            if (mb_strlen($label) > 190) {
                $this->fail('Libelle trop long', 400);
            }

            $movement->fill([
                'cash_register_day_id' => $day->id,
                'type' => $type,
                'label' => $label,
                'amount' => $this->positiveDecimal($data['amount'] ?? 0, 'montant'),
                'occurred_on' => $this->date((string) ($data['occurredOn'] ?? $data['occurred_on'] ?? $day->cash_date?->format('Y-m-d')), 'date du mouvement'),
                'sort_order' => (int) ($data['sortOrder'] ?? $data['sort_order'] ?? ($movement->sort_order ?: 100)),
            ]);

            $attachmentDataUrl = trim((string) ($data['attachmentDataUrl'] ?? $data['attachment_data_url'] ?? ''));
            if ($attachmentDataUrl !== '') {
                $attachment = $this->storeAttachment(
                    $day,
                    $attachmentDataUrl,
                    (string) ($data['attachmentName'] ?? $data['attachment_name'] ?? ''),
                    $actor,
                    $movement->getAttribute('justification_path'),
                );

                $movement->fill($attachment);
            }

            $movement->save();

            $day = $this->recalculateAndSave($day->refresh()->load(['movements', 'receipts', 'cashCountLines']));
            $this->activity->log($actor, $id > 0 ? 'modification mouvement caisse' : 'creation mouvement caisse', "Caisse #{$day->id} - mouvement #{$movement->id}");

            return ['ok' => true, 'movement' => $this->movementRow($movement->refresh()), 'day' => $this->dayRow($day)];
        });
    }

    public function saveReceipt(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $dayId = (int) ($data['dayId'] ?? $data['day_id'] ?? $data['cashRegisterDayId'] ?? 0);

            if ($dayId <= 0) {
                $this->fail('Jour de caisse requis', 400);
            }

            $day = CrmCashRegisterDay::query()
                ->with(['movements', 'receipts', 'cashCountLines'])
                ->lockForUpdate()
                ->find($dayId);

            if (! $day) {
                $this->fail('Jour de caisse introuvable', 404);
            }

            $this->requireSitePermission($actor, (int) $day->site_id, self::MANAGE_PERMISSION);

            $id = max(0, (int) ($data['id'] ?? 0));
            $receipt = $id > 0
                ? CrmCashReceipt::query()->lockForUpdate()->where('cash_register_day_id', $day->id)->find($id)
                : new CrmCashReceipt;

            if ($id > 0 && ! $receipt) {
                $this->fail('Encaissement introuvable', 404);
            }

            $cashAmount = $this->nonNegativeDecimal($data['cashAmount'] ?? $data['cash_amount'] ?? 0, 'especes');
            $cardAmount = $this->nonNegativeDecimal($data['cardAmount'] ?? $data['card_amount'] ?? 0, 'carte bancaire');
            $checkAmount = $this->nonNegativeDecimal($data['checkAmount'] ?? $data['check_amount'] ?? 0, 'cheque');
            $transferAmount = $this->nonNegativeDecimal($data['transferAmount'] ?? $data['transfer_amount'] ?? 0, 'virement');
            $controlAmount = $this->nonNegativeDecimal($data['controlAmount'] ?? $data['control_amount'] ?? 0, 'autre paiement');
            $paymentsTotal = $this->money($cashAmount + $cardAmount + $checkAmount + $transferAmount + $controlAmount);
            $invoiceTotal = $this->nonNegativeDecimal($data['invoiceTotal'] ?? $data['invoice_total'] ?? 0, 'total facture');

            if ($invoiceTotal <= 0 && $paymentsTotal > 0) {
                $invoiceTotal = $paymentsTotal;
            }

            if ($invoiceTotal <= 0 && $paymentsTotal <= 0) {
                $this->fail('Montant encaissement requis', 400);
            }

            $customerName = $this->limitedText($data['customerName'] ?? $data['customer_name'] ?? '', 190, 'Client');
            if ($customerName === '') {
                $customerName = 'Client comptoir';
            }

            $receipt->fill([
                'cash_register_day_id' => $day->id,
                'invoice_number' => $this->limitedText($data['invoiceNumber'] ?? $data['invoice_number'] ?? '', 80, 'Numero facture'),
                'customer_name' => $customerName,
                'occurred_on' => $this->date((string) ($data['occurredOn'] ?? $data['occurred_on'] ?? $day->cash_date?->format('Y-m-d')), 'date encaissement'),
                'invoice_total' => $invoiceTotal,
                'cash_amount' => $cashAmount,
                'card_amount' => $cardAmount,
                'check_amount' => $checkAmount,
                'transfer_amount' => $transferAmount,
                'control_amount' => $controlAmount,
                'payment_note' => $this->limitedText($data['paymentNote'] ?? $data['payment_note'] ?? '', 190, 'Note paiement'),
                'sort_order' => (int) ($data['sortOrder'] ?? $data['sort_order'] ?? ($receipt->sort_order ?: 100)),
                'updated_by' => $actor->id,
            ]);

            if (! $receipt->exists) {
                $receipt->created_by = $actor->id;
            }

            $receipt->save();

            $day = $this->syncPaymentTotalsFromReceipts($day->refresh()->load(['movements', 'receipts', 'cashCountLines']));
            $day = $this->recalculateAndSave($day);
            $this->activity->log($actor, $id > 0 ? 'modification encaissement caisse' : 'creation encaissement caisse', "Caisse #{$day->id} - encaissement #{$receipt->id}");

            return ['ok' => true, 'receipt' => $this->receiptRow($receipt->refresh()), 'day' => $this->dayRow($day)];
        });
    }

    public function deleteMovement(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);

            if ($id <= 0) {
                $this->fail('Mouvement requis', 400);
            }

            $movement = CrmCashMovement::query()
                ->with('day')
                ->lockForUpdate()
                ->find($id);

            if (! $movement || ! $movement->day) {
                $this->fail('Mouvement introuvable', 404);
            }

            $day = $movement->day;
            $this->requireSitePermission($actor, (int) $day->site_id, self::MANAGE_PERMISSION);

            $movement->delete();
            $day = $this->recalculateAndSave($day->refresh()->load(['movements', 'receipts', 'cashCountLines']));
            $this->activity->log($actor, 'suppression mouvement caisse', "Caisse #{$day->id} - mouvement #{$id}");

            return ['ok' => true, 'deleted' => true, 'day' => $this->dayRow($day)];
        });
    }

    public function deleteReceipt(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);

            if ($id <= 0) {
                $this->fail('Encaissement requis', 400);
            }

            $receipt = CrmCashReceipt::query()
                ->with('day')
                ->lockForUpdate()
                ->find($id);

            if (! $receipt || ! $receipt->day) {
                $this->fail('Encaissement introuvable', 404);
            }

            $day = $receipt->day;
            $this->requireSitePermission($actor, (int) $day->site_id, self::MANAGE_PERMISSION);

            $receipt->delete();
            $day = $this->syncPaymentTotalsFromReceipts($day->refresh()->load(['movements', 'receipts', 'cashCountLines']));
            $day = $this->recalculateAndSave($day);
            $this->activity->log($actor, 'suppression encaissement caisse', "Caisse #{$day->id} - encaissement #{$id}");

            return ['ok' => true, 'deleted' => true, 'day' => $this->dayRow($day)];
        });
    }

    public function saveCashCount(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $dayId = (int) ($data['dayId'] ?? $data['day_id'] ?? $data['cashRegisterDayId'] ?? 0);

            if ($dayId <= 0) {
                $this->fail('Jour de caisse requis', 400);
            }

            $day = CrmCashRegisterDay::query()
                ->with(['movements', 'receipts', 'cashCountLines'])
                ->lockForUpdate()
                ->find($dayId);

            if (! $day) {
                $this->fail('Jour de caisse introuvable', 404);
            }

            $this->requireSitePermission($actor, (int) $day->site_id, self::MANAGE_PERMISSION);

            $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
            $indexed = [];
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $denomination = $this->money($line['denomination'] ?? $line['value'] ?? 0);
                $indexed[(string) $denomination] = $line;
            }

            CrmCashCountLine::query()
                ->where('cash_register_day_id', $day->id)
                ->delete();

            foreach (self::CASH_DENOMINATIONS as $index => $definition) {
                $denomination = $this->money($definition['value']);
                $line = $indexed[(string) $denomination] ?? [];

                CrmCashCountLine::query()->create([
                    'cash_register_day_id' => $day->id,
                    'kind' => $definition['kind'],
                    'denomination' => $denomination,
                    'previous_quantity' => $this->nonNegativeInt($line['previousQuantity'] ?? $line['previous_quantity'] ?? 0, 'quantite veille'),
                    'current_quantity' => $this->nonNegativeInt($line['currentQuantity'] ?? $line['current_quantity'] ?? 0, 'quantite jour'),
                    'deposit_quantity' => $this->nonNegativeInt($line['depositQuantity'] ?? $line['deposit_quantity'] ?? 0, 'quantite remise banque'),
                    'sort_order' => ($index + 1) * 10,
                ]);
            }

            $day->fill([
                'check_counted' => $this->nullableDecimal($data['checkCounted'] ?? $data['check_counted'] ?? $day->check_counted),
                'transfer_counted' => $this->nullableDecimal($data['transferCounted'] ?? $data['transfer_counted'] ?? $day->transfer_counted),
                'card_counted' => $this->nullableDecimal($data['cardCounted'] ?? $data['card_counted'] ?? $day->card_counted),
                'updated_by' => $actor->id,
            ]);

            $day = $day->refresh()->load(['movements', 'receipts', 'cashCountLines']);
            $totals = $this->cashCountTotals($day);
            $bankParts = array_filter([
                $day->check_counted,
                $day->transfer_counted,
                $day->card_counted,
            ], fn ($value): bool => $value !== null);

            $day->forceFill([
                'counted_cash' => $totals['countedCash'],
                'bank_counted' => $bankParts === []
                    ? $day->bank_counted
                    : $this->money(array_sum(array_map('floatval', $bankParts))),
                'updated_by' => $actor->id,
            ]);

            $day = $this->recalculateAndSave($day);
            $this->activity->log($actor, 'comptage especes caisse', "Caisse #{$day->id}");

            return ['ok' => true, 'day' => $this->dayRow($day)];
        });
    }

    public function deleteDay(CrmUser $actor, array $data): array
    {
        $this->requireModule($actor);

        return DB::transaction(function () use ($actor, $data): array {
            $id = (int) ($data['id'] ?? 0);

            if ($id <= 0) {
                $this->fail('Jour de caisse requis', 400);
            }

            $day = CrmCashRegisterDay::query()->lockForUpdate()->find($id);

            if (! $day) {
                $this->fail('Jour de caisse introuvable', 404);
            }

            $this->requireSitePermission($actor, (int) $day->site_id, self::MANAGE_PERMISSION);

            $details = 'Caisse '.$day->cash_date?->format('Y-m-d').' - site #'.$day->site_id;
            $day->delete();
            $this->activity->log($actor, 'suppression controle caisse', $details);

            return ['ok' => true, 'deleted' => true];
        });
    }

    private function recalculateAndSave(CrmCashRegisterDay $day): CrmCashRegisterDay
    {
        $day->loadMissing(['movements', 'receipts', 'cashCountLines']);
        $day->status = $this->calculations($day)['status'];
        $day->save();

        return $day->refresh()->load(['movements', 'receipts', 'cashCountLines']);
    }

    /**
     * @return array<string, mixed>
     */
    private function calculations(CrmCashRegisterDay $day): array
    {
        $movements = $day->relationLoaded('movements')
            ? $day->movements
            : $day->movements()->get();
        $receipts = $day->relationLoaded('receipts')
            ? $day->receipts
            : $day->receipts()->get();
        $cashCountLines = $day->relationLoaded('cashCountLines')
            ? $day->cashCountLines
            : $day->cashCountLines()->get();

        $cashInTotal = $this->movementTotal($movements, CrmCashMovement::TYPE_CASH_IN);
        $cashOutTotal = $this->movementTotal($movements, CrmCashMovement::TYPE_CASH_OUT);
        $cashCount = $this->cashCountTotals($day, $cashCountLines);
        $countedCash = $cashCount['hasCashCount'] ? $cashCount['countedCash'] : $day->counted_cash;
        $controlTotal = $this->money($receipts->sum(fn (CrmCashReceipt $receipt): float => (float) $receipt->control_amount));
        $paymentsTotal = $this->money($day->cash_sales + $day->card_sales + $day->check_sales + $day->transfer_sales + $controlTotal);
        $entryDifference = $this->money($day->invoice_total - $paymentsTotal);
        $expectedCash = $this->money($day->opening_balance + $day->cash_sales + $cashInTotal - $cashOutTotal - $cashCount['cashDepositTotal']);
        $cashDifference = $countedCash === null ? null : $this->money($countedCash - $expectedCash);
        $bankExpected = $this->money($day->card_sales + $day->check_sales + $day->transfer_sales + $controlTotal);
        $bankDifference = $day->bank_counted === null ? null : $this->money($day->bank_counted - $bankExpected);
        $checkDifference = $day->check_counted === null ? null : $this->money($day->check_counted - $day->check_sales);
        $transferDifference = $day->transfer_counted === null ? null : $this->money($day->transfer_counted - $day->transfer_sales);
        $cardDifference = $day->card_counted === null ? null : $this->money($day->card_counted - $day->card_sales);
        $hasGranularControls = $day->check_counted !== null || $day->transfer_counted !== null || $day->card_counted !== null;

        $status = CrmCashRegisterDay::STATUS_OK;
        if (
            $countedCash === null
            || (! $hasGranularControls && $bankExpected > 0 && $day->bank_counted === null)
            || ($hasGranularControls && $day->check_sales > 0 && $day->check_counted === null)
            || ($hasGranularControls && $day->transfer_sales > 0 && $day->transfer_counted === null)
            || ($hasGranularControls && $day->card_sales > 0 && $day->card_counted === null)
        ) {
            $status = CrmCashRegisterDay::STATUS_REVIEW;
        } elseif (
            abs($entryDifference) > 0.01
            || abs((float) $cashDifference) > 0.01
            || (! $hasGranularControls && $bankDifference !== null && abs($bankDifference) > 0.01)
            || ($checkDifference !== null && abs($checkDifference) > 0.01)
            || ($transferDifference !== null && abs($transferDifference) > 0.01)
            || ($cardDifference !== null && abs($cardDifference) > 0.01)
            || (int) $day->invoice_errors_count > 0
        ) {
            $status = CrmCashRegisterDay::STATUS_ANOMALY;
        }

        return [
            'cashInTotal' => $cashInTotal,
            'cashOutTotal' => $cashOutTotal,
            'cashGrossTotal' => $cashCount['cashGrossTotal'],
            'cashDepositTotal' => $cashCount['cashDepositTotal'],
            'hasCashCount' => $cashCount['hasCashCount'],
            'controlTotal' => $controlTotal,
            'receiptCount' => $receipts->count(),
            'paymentsTotal' => $paymentsTotal,
            'entryDifference' => $entryDifference,
            'expectedCash' => $expectedCash,
            'cashDifference' => $cashDifference,
            'bankExpected' => $bankExpected,
            'bankDifference' => $bankDifference,
            'checkDifference' => $checkDifference,
            'transferDifference' => $transferDifference,
            'cardDifference' => $cardDifference,
            'status' => $status,
        ];
    }

    /**
     * @param  Collection<int, CrmCashMovement>  $movements
     */
    private function movementTotal(Collection $movements, string $type): float
    {
        return $this->money($movements
            ->where('type', $type)
            ->sum(fn (CrmCashMovement $movement): float => (float) $movement->amount));
    }

    /**
     * @param  Collection<int, CrmCashCountLine>|null  $lines
     * @return array{cashGrossTotal: float, cashDepositTotal: float, countedCash: float|null, hasCashCount: bool}
     */
    private function cashCountTotals(CrmCashRegisterDay $day, ?Collection $lines = null): array
    {
        $lines ??= $day->relationLoaded('cashCountLines')
            ? $day->cashCountLines
            : $day->cashCountLines()->get();

        $hasCashCount = $lines->isNotEmpty();
        $cashGrossTotal = $this->money($lines->sum(
            fn (CrmCashCountLine $line): float => (float) $line->denomination * (int) $line->current_quantity,
        ));
        $cashDepositTotal = $this->money($lines->sum(
            fn (CrmCashCountLine $line): float => (float) $line->denomination * (int) $line->deposit_quantity,
        ));
        $countedCash = $hasCashCount ? $this->money($cashGrossTotal - $cashDepositTotal) : null;

        return [
            'cashGrossTotal' => $cashGrossTotal,
            'cashDepositTotal' => $cashDepositTotal,
            'countedCash' => $countedCash,
            'hasCashCount' => $hasCashCount,
        ];
    }

    private function syncPaymentTotalsFromReceipts(CrmCashRegisterDay $day): CrmCashRegisterDay
    {
        $day->loadMissing('receipts');
        $receipts = $day->receipts;

        $day->forceFill([
            'invoice_total' => $this->money($receipts->sum(fn (CrmCashReceipt $receipt): float => (float) $receipt->invoice_total)),
            'cash_sales' => $this->money($receipts->sum(fn (CrmCashReceipt $receipt): float => (float) $receipt->cash_amount)),
            'card_sales' => $this->money($receipts->sum(fn (CrmCashReceipt $receipt): float => (float) $receipt->card_amount)),
            'check_sales' => $this->money($receipts->sum(fn (CrmCashReceipt $receipt): float => (float) $receipt->check_amount)),
            'transfer_sales' => $this->money($receipts->sum(fn (CrmCashReceipt $receipt): float => (float) $receipt->transfer_amount)),
        ]);

        return $day;
    }

    private function openingBalanceFromPreviousDay(int $siteId, string $cashDate): float
    {
        $previous = CrmCashRegisterDay::query()
            ->with(['movements', 'receipts', 'cashCountLines'])
            ->where('site_id', $siteId)
            ->whereDate('cash_date', '<', $cashDate)
            ->orderByDesc('cash_date')
            ->orderByDesc('id')
            ->first();

        if (! $previous) {
            return 0.0;
        }

        return $this->money($previous->counted_cash ?? $this->calculations($previous)['expectedCash']);
    }

    private function nextDate(int $siteId): string
    {
        $lastDate = CrmCashRegisterDay::query()
            ->where('site_id', $siteId)
            ->max('cash_date');

        if (! $lastDate) {
            return CarbonImmutable::today()->format('Y-m-d');
        }

        return CarbonImmutable::parse($lastDate)->addDay()->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    private function dayRow(CrmCashRegisterDay $day): array
    {
        $day->loadMissing(['movements', 'receipts', 'cashCountLines']);
        $calculations = $this->calculations($day);

        return [
            'id' => $day->id,
            'siteId' => (int) $day->site_id,
            'cashDate' => $day->cash_date?->format('Y-m-d'),
            'openingBalance' => $this->money($day->opening_balance),
            'invoiceTotal' => $this->money($day->invoice_total),
            'cashSales' => $this->money($day->cash_sales),
            'cardSales' => $this->money($day->card_sales),
            'checkSales' => $this->money($day->check_sales),
            'transferSales' => $this->money($day->transfer_sales),
            'countedCash' => $day->counted_cash === null ? null : $this->money($day->counted_cash),
            'bankCounted' => $day->bank_counted === null ? null : $this->money($day->bank_counted),
            'checkCounted' => $day->check_counted === null ? null : $this->money($day->check_counted),
            'transferCounted' => $day->transfer_counted === null ? null : $this->money($day->transfer_counted),
            'cardCounted' => $day->card_counted === null ? null : $this->money($day->card_counted),
            'invoiceErrorsCount' => (int) $day->invoice_errors_count,
            'notes' => $day->notes ?? '',
            'status' => $calculations['status'],
            'statusLabel' => $this->statusLabel($calculations['status']),
            'statusColor' => $this->statusColor($calculations['status']),
            'cashInTotal' => $calculations['cashInTotal'],
            'cashOutTotal' => $calculations['cashOutTotal'],
            'cashGrossTotal' => $calculations['cashGrossTotal'],
            'cashDepositTotal' => $calculations['cashDepositTotal'],
            'hasCashCount' => $calculations['hasCashCount'],
            'controlTotal' => $calculations['controlTotal'],
            'receiptCount' => $calculations['receiptCount'],
            'paymentsTotal' => $calculations['paymentsTotal'],
            'entryDifference' => $calculations['entryDifference'],
            'expectedCash' => $calculations['expectedCash'],
            'cashDifference' => $calculations['cashDifference'],
            'bankExpected' => $calculations['bankExpected'],
            'bankDifference' => $calculations['bankDifference'],
            'checkDifference' => $calculations['checkDifference'],
            'transferDifference' => $calculations['transferDifference'],
            'cardDifference' => $calculations['cardDifference'],
            'cashCountLines' => $this->cashCountRows($day),
            'receipts' => $day->receipts
                ->map(fn (CrmCashReceipt $receipt): array => $this->receiptRow($receipt))
                ->values()
                ->all(),
            'movements' => $day->movements
                ->map(fn (CrmCashMovement $movement): array => $this->movementRow($movement))
                ->values()
                ->all(),
            'updatedAt' => $day->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptRow(CrmCashReceipt $receipt): array
    {
        return [
            'id' => $receipt->id,
            'dayId' => (int) $receipt->cash_register_day_id,
            'invoiceNumber' => $receipt->invoice_number ?? '',
            'customerName' => $receipt->customer_name ?? '',
            'occurredOn' => $receipt->occurred_on?->format('Y-m-d'),
            'invoiceTotal' => $this->money($receipt->invoice_total),
            'cashAmount' => $this->money($receipt->cash_amount),
            'cardAmount' => $this->money($receipt->card_amount),
            'checkAmount' => $this->money($receipt->check_amount),
            'transferAmount' => $this->money($receipt->transfer_amount),
            'controlAmount' => $this->money($receipt->control_amount),
            'paymentNote' => $receipt->payment_note ?? '',
            'sortOrder' => (int) $receipt->sort_order,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cashCountRows(CrmCashRegisterDay $day): array
    {
        $existing = $day->relationLoaded('cashCountLines')
            ? $day->cashCountLines
            : $day->cashCountLines()->get();
        $byValue = $existing->keyBy(fn (CrmCashCountLine $line): string => (string) $this->money($line->denomination));

        return collect(self::CASH_DENOMINATIONS)
            ->map(function (array $definition, int $index) use ($byValue): array {
                $denomination = $this->money($definition['value']);
                /** @var CrmCashCountLine|null $line */
                $line = $byValue->get((string) $denomination);
                $previousQuantity = (int) ($line?->previous_quantity ?? 0);
                $currentQuantity = (int) ($line?->current_quantity ?? 0);
                $depositQuantity = (int) ($line?->deposit_quantity ?? 0);

                return [
                    'id' => $line?->id,
                    'kind' => $definition['kind'],
                    'label' => $definition['label'],
                    'denomination' => $denomination,
                    'previousQuantity' => $previousQuantity,
                    'currentQuantity' => $currentQuantity,
                    'depositQuantity' => $depositQuantity,
                    'previousTotal' => $this->money($previousQuantity * $denomination),
                    'currentTotal' => $this->money($currentQuantity * $denomination),
                    'depositTotal' => $this->money($depositQuantity * $denomination),
                    'sortOrder' => ($index + 1) * 10,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function movementRow(CrmCashMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'dayId' => (int) $movement->cash_register_day_id,
            'type' => $movement->type,
            'typeLabel' => CrmCashMovement::typeOptions()[$movement->type] ?? $movement->type,
            'label' => $movement->label,
            'amount' => $this->money($movement->amount),
            'occurredOn' => $movement->occurred_on?->format('Y-m-d'),
            'sortOrder' => (int) $movement->sort_order,
            'justificationPath' => $movement->justification_path ?? '',
            'originalName' => $movement->original_name ?? '',
            'mimeType' => $movement->mime_type ?? '',
            'size' => (int) ($movement->size ?? 0),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, color: string}>
     */
    private function statusRows(): array
    {
        return collect([
            CrmCashRegisterDay::STATUS_OK,
            CrmCashRegisterDay::STATUS_ANOMALY,
            CrmCashRegisterDay::STATUS_REVIEW,
        ])->map(fn (string $status): array => [
            'value' => $status,
            'label' => $this->statusLabel($status),
            'color' => $this->statusColor($status),
        ])->all();
    }

    private function statusLabel(string $status): string
    {
        return [
            CrmCashRegisterDay::STATUS_OK => 'Caisse OK',
            CrmCashRegisterDay::STATUS_ANOMALY => 'Anomalie',
            CrmCashRegisterDay::STATUS_REVIEW => 'A verifier',
        ][$status] ?? 'A verifier';
    }

    private function statusColor(string $status): string
    {
        return [
            CrmCashRegisterDay::STATUS_OK => '#16a34a',
            CrmCashRegisterDay::STATUS_ANOMALY => '#dc2626',
            CrmCashRegisterDay::STATUS_REVIEW => '#64748b',
        ][$status] ?? '#64748b';
    }

    /**
     * @return array<string, mixed>
     */
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

    private function storeAttachment(CrmCashRegisterDay $day, string $dataUrl, string $originalName, CrmUser $actor, ?string $replacePath): array
    {
        if (! preg_match('/^data:(application\/pdf|image\/(?:png|jpe?g|webp));base64,/', $dataUrl, $matches)) {
            $this->fail('Justificatif invalide', 400);
        }

        $mime = $matches[1];
        $date = $day->cash_date?->format('Y-m-d') ?: CarbonImmutable::today()->format('Y-m-d');
        $folder = 'cash-control/site-'.$day->site_id.'/'.$date;

        if (str_starts_with($mime, 'image/')) {
            $stored = $this->images->storeDataUrl($dataUrl, $folder, $replacePath, [
                'maxBytes' => 5 * 1024 * 1024,
                'label' => 'Justificatif',
            ]);

            return [
                'justification_path' => $stored['url'],
                'original_name' => $this->safeFileName($originalName, 'justificatif.webp'),
                'mime_type' => $stored['mime'],
                'size' => $stored['size'],
                'uploaded_by' => $actor->id,
            ];
        }

        $binary = base64_decode(substr($dataUrl, (int) strpos($dataUrl, ',') + 1), true);

        if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
            $this->fail('Justificatif trop lourd', 400);
        }

        $relativePath = 'assets/uploads/'.$folder.'/'.now()->format('YmdHis').'-'.Str::random(10).'.pdf';

        if (! Storage::disk('public')->put($relativePath, $binary, ['visibility' => 'public'])) {
            $this->fail('Impossible de stocker le justificatif', 500);
        }

        $this->images->deletePublicUpload($replacePath);

        return [
            'justification_path' => '/storage/'.str_replace('\\', '/', $relativePath),
            'original_name' => $this->safeFileName($originalName, 'justificatif.pdf'),
            'mime_type' => $mime,
            'size' => strlen($binary),
            'uploaded_by' => $actor->id,
        ];
    }

    private function safeFileName(string $value, string $fallback): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '', $value));

        if ($name === '') {
            return $fallback;
        }

        return Str::limit($name, 120, '');
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

    private function availableSites(CrmUser $actor): Collection
    {
        return CrmSite::query()
            ->active()
            ->whereIn('id', $this->siteIds($actor))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, int>
     */
    private function siteIds(CrmUser $user): array
    {
        return $this->access->siteIdsForModule($user, self::MODULE, [
            self::VIEW_PERMISSION,
            self::MANAGE_PERMISSION,
        ]);
    }

    private function requireModule(CrmUser $actor): void
    {
        if (! $this->access->hasModule($actor, self::MODULE)) {
            $this->fail('Module non autorise : controle-caisse', 403);
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

    private function date(string $value, string $label): string
    {
        try {
            $date = CarbonImmutable::parse(trim($value));
        } catch (Throwable) {
            $this->fail('Date invalide : '.$label, 400);
        }

        return $date->format('Y-m-d');
    }

    private function decimal(mixed $value): float
    {
        $value = trim(str_replace(',', '.', (string) $value));

        if ($value === '') {
            return 0.0;
        }

        if (! is_numeric($value)) {
            $this->fail('Montant invalide', 400);
        }

        return $this->money((float) $value);
    }

    private function nullableDecimal(mixed $value): ?float
    {
        $value = trim(str_replace(',', '.', (string) $value));

        if ($value === '') {
            return null;
        }

        return $this->decimal($value);
    }

    private function positiveDecimal(mixed $value, string $label): float
    {
        $amount = $this->decimal($value);

        if ($amount <= 0) {
            $this->fail('Montant invalide : '.$label, 400);
        }

        return $amount;
    }

    private function nonNegativeDecimal(mixed $value, string $label): float
    {
        $amount = $this->decimal($value);

        if ($amount < 0) {
            $this->fail('Montant invalide : '.$label, 400);
        }

        return $amount;
    }

    private function nonNegativeInt(mixed $value, string $label): int
    {
        $int = (int) $value;

        if ($int < 0 || $int > 999) {
            $this->fail('Valeur invalide : '.$label, 400);
        }

        return $int;
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function limitedText(mixed $value, int $maxLength, string $label): string
    {
        $text = trim((string) $value);

        if (mb_strlen($text) > $maxLength) {
            $this->fail($label.' trop long', 400);
        }

        return $text;
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function fail(string $message, int $status): never
    {
        throw new HttpException($status, $message);
    }
}
