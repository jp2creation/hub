<?php

namespace Tests\Feature;

use App\Models\CrmCashCountLine;
use App\Models\CrmCashMovement;
use App\Models\CrmCashReceipt;
use App\Models\CrmCashRegisterDay;
use App\Models\CrmCheckRemittance;
use App\Models\CrmCheckRemittanceLine;
use App\Models\CrmDepositRequest;
use App\Models\CrmEquipmentCategory;
use App\Models\CrmEquipmentItem;
use App\Models\CrmEquipmentRental;
use App\Models\CrmLeaveEmployee;
use App\Models\CrmLeaveEntry;
use App\Models\CrmLeaveStatusHistory;
use App\Models\CrmLeaveTransaction;
use App\Models\CrmReservation;
use App\Models\CrmSalesTour;
use App\Models\CrmSalesVisit;
use App\Models\CrmSite;
use App\Models\CrmUser;
use App\Models\CrmVehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmArchiveCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_crm_archive_dry_run_only_counts_candidates(): void
    {
        Carbon::setTestNow('2026-07-19 12:00:00');

        [$site, $crmUser, $vehicle, $item] = $this->archiveFixtures();

        CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Ancienne reservation',
            'start_at' => '2023-07-10 08:00:00',
            'end_at' => '2023-07-10 09:00:00',
        ]);

        CrmEquipmentRental::query()->create([
            'site_id' => $site->id,
            'equipment_item_id' => $item->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'period_type' => 'half_day',
            'slot' => 'morning',
            'status' => CrmEquipmentRental::STATUS_RETURNED,
            'title' => 'Ancienne location',
            'start_at' => '2023-07-11 08:00:00',
            'end_at' => '2023-07-11 12:00:00',
        ]);

        $this->artisan('crm:archive', ['--years' => 2, '--dry-run' => true])
            ->expectsOutput('Archivage HUB en dry-run.')
            ->expectsOutput('Reservations vehicules : 1 candidat(s) avant 2024-07-19 -> crm_archived_reservations')
            ->expectsOutput('Locations materiel : 1 candidat(s) avant 2024-07-19 -> crm_archived_equipment_rentals')
            ->expectsOutput('Total candidat(s) : 2')
            ->assertSuccessful();

        $this->assertDatabaseCount('crm_reservations', 1);
        $this->assertDatabaseCount('crm_equipment_rentals', 1);
        $this->assertDatabaseCount('crm_archived_reservations', 0);
        $this->assertDatabaseCount('crm_archived_equipment_rentals', 0);

    }

    public function test_crm_archive_moves_finished_old_reservations_and_rentals_to_archive_tables(): void
    {
        Carbon::setTestNow('2026-07-19 12:00:00');

        [$site, $crmUser, $vehicle, $item] = $this->archiveFixtures();

        $oldReservation = CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Ancienne reservation',
            'start_at' => '2023-07-10 08:00:00',
            'end_at' => '2023-07-10 09:00:00',
        ]);
        CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Reservation recente',
            'start_at' => '2025-07-10 10:00:00',
            'end_at' => '2025-07-10 11:00:00',
        ]);

        $oldRental = CrmEquipmentRental::query()->create([
            'site_id' => $site->id,
            'equipment_item_id' => $item->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'period_type' => 'half_day',
            'slot' => 'morning',
            'status' => CrmEquipmentRental::STATUS_RETURNED,
            'title' => 'Ancienne location',
            'start_at' => '2023-07-11 08:00:00',
            'end_at' => '2023-07-11 12:00:00',
        ]);
        CrmEquipmentRental::query()->create([
            'site_id' => $site->id,
            'equipment_item_id' => $item->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'period_type' => 'half_day',
            'slot' => 'afternoon',
            'status' => CrmEquipmentRental::STATUS_RESERVED,
            'title' => 'Ancienne location encore active',
            'start_at' => '2023-07-12 13:30:00',
            'end_at' => '2023-07-12 17:30:00',
        ]);

        $this->artisan('crm:archive', ['--years' => 2, '--limit' => 1])
            ->assertSuccessful();

        $this->assertDatabaseMissing('crm_reservations', ['id' => $oldReservation->id]);
        $this->assertDatabaseMissing('crm_equipment_rentals', ['id' => $oldRental->id]);
        $this->assertDatabaseHas('crm_reservations', ['title' => 'Reservation recente']);
        $this->assertDatabaseHas('crm_equipment_rentals', ['title' => 'Ancienne location encore active']);
        $this->assertDatabaseHas('crm_archived_reservations', ['original_id' => $oldReservation->id]);
        $this->assertDatabaseHas('crm_archived_equipment_rentals', ['original_id' => $oldRental->id]);

        $reservationArchive = DB::table('crm_archived_reservations')
            ->where('original_id', $oldReservation->id)
            ->first();
        $rentalArchive = DB::table('crm_archived_equipment_rentals')
            ->where('original_id', $oldRental->id)
            ->first();

        $this->assertSame('Ancienne reservation', json_decode((string) $reservationArchive->data, true)['title']);
        $this->assertSame('Ancienne location', json_decode((string) $rentalArchive->data, true)['title']);

    }

    public function test_crm_archive_can_target_one_configured_model(): void
    {
        Carbon::setTestNow('2026-07-19 12:00:00');

        [$site, $crmUser, $vehicle, $item] = $this->archiveFixtures();

        $reservation = CrmReservation::query()->create([
            'site_id' => $site->id,
            'vehicle_id' => $vehicle->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'title' => 'Reservation non ciblee',
            'start_at' => '2023-07-10 08:00:00',
            'end_at' => '2023-07-10 09:00:00',
        ]);
        $rental = CrmEquipmentRental::query()->create([
            'site_id' => $site->id,
            'equipment_item_id' => $item->id,
            'user_id' => $crmUser->id,
            'user_name' => $crmUser->name,
            'period_type' => 'half_day',
            'slot' => 'morning',
            'status' => CrmEquipmentRental::STATUS_RETURNED,
            'title' => 'Location ciblee',
            'start_at' => '2023-07-11 08:00:00',
            'end_at' => '2023-07-11 12:00:00',
        ]);

        $this->artisan('crm:archive', ['--years' => 2, '--model' => 'equipment_rentals'])
            ->assertSuccessful();

        $this->assertDatabaseHas('crm_reservations', ['id' => $reservation->id]);
        $this->assertDatabaseMissing('crm_equipment_rentals', ['id' => $rental->id]);
        $this->assertDatabaseCount('crm_archived_reservations', 0);
        $this->assertDatabaseHas('crm_archived_equipment_rentals', ['original_id' => $rental->id]);
    }

    public function test_crm_archive_moves_configured_business_modules_with_their_children(): void
    {
        Carbon::setTestNow('2026-07-19 12:00:00');

        [$site, $crmUser] = $this->archiveFixtures();
        $employee = CrmLeaveEmployee::query()->create([
            'crm_user_id' => $crmUser->id,
            'name' => 'Archive Employee',
            'slug' => 'archive-employee',
            'color' => '#95002e',
            'active' => true,
            'sort_order' => 10,
        ]);

        $leave = CrmLeaveEntry::query()->create([
            'employee_id' => $employee->id,
            'start_date' => '2023-07-03',
            'end_date' => '2023-07-04',
            'type' => 'conge',
            'period' => 'full',
            'duration_days' => 2,
            'status' => 'approved',
            'notes' => 'Archive leave',
            'source' => 'crm',
            'created_by' => $crmUser->id,
        ]);
        CrmLeaveTransaction::query()->create([
            'entry_id' => $leave->id,
            'employee_id' => $employee->id,
            'type' => 'conge',
            'year' => 2023,
            'amount_days' => -2,
            'balance_after' => 20,
            'reason' => 'Archive test',
            'created_by' => $crmUser->id,
        ]);
        CrmLeaveStatusHistory::query()->create([
            'entry_id' => $leave->id,
            'employee_id' => $employee->id,
            'from_status' => 'pending',
            'to_status' => 'approved',
            'changed_by' => $crmUser->id,
            'reason' => 'Archive test',
            'changed_at' => '2023-07-01 08:00:00',
        ]);

        $cashDay = CrmCashRegisterDay::query()->create([
            'site_id' => $site->id,
            'cash_date' => '2023-07-05',
            'opening_balance' => 100,
            'invoice_total' => 200,
            'cash_sales' => 80,
            'card_sales' => 120,
            'status' => CrmCashRegisterDay::STATUS_OK,
            'created_by' => $crmUser->id,
        ]);
        CrmCashMovement::query()->create([
            'cash_register_day_id' => $cashDay->id,
            'type' => CrmCashMovement::TYPE_CASH_OUT,
            'label' => 'Archive sortie',
            'amount' => 12,
            'occurred_on' => '2023-07-05',
            'sort_order' => 10,
        ]);
        CrmCashReceipt::query()->create([
            'cash_register_day_id' => $cashDay->id,
            'invoice_number' => 'F-ARCH',
            'customer_name' => 'Client Archive',
            'occurred_on' => '2023-07-05',
            'invoice_total' => 120,
            'card_amount' => 120,
            'sort_order' => 10,
            'created_by' => $crmUser->id,
        ]);
        CrmCashCountLine::query()->create([
            'cash_register_day_id' => $cashDay->id,
            'kind' => 'cash',
            'denomination' => 20,
            'previous_quantity' => 1,
            'current_quantity' => 2,
            'deposit_quantity' => 0,
            'sort_order' => 10,
        ]);

        $remittance = CrmCheckRemittance::query()->create([
            'site_id' => $site->id,
            'remittance_date' => '2023-07-06',
            'reference' => 'R-ARCH',
            'status' => CrmCheckRemittance::STATUS_DEPOSITED,
            'check_count' => 1,
            'total_amount' => 150,
            'created_by' => $crmUser->id,
        ]);
        CrmCheckRemittanceLine::query()->create([
            'check_remittance_id' => $remittance->id,
            'payer_name' => 'Payeur Archive',
            'invoice_number' => 'F-ARCH',
            'check_number' => 'C-1',
            'check_date' => '2023-07-06',
            'amount' => 150,
            'sort_order' => 10,
            'created_by' => $crmUser->id,
        ]);

        $deposit = CrmDepositRequest::query()->create([
            'site_id' => $site->id,
            'request_date' => '2023-07-07',
            'requester_user_id' => $crmUser->id,
            'requester_name' => $crmUser->name,
            'document_number' => 'CMD-ARCH',
            'client_name' => 'Client Acompte',
            'amount' => 300,
            'status' => CrmDepositRequest::STATUS_VALIDATED,
            'validated_at' => '2023-07-07 10:00:00',
            'validated_by' => $crmUser->id,
            'created_by' => $crmUser->id,
        ]);

        $tour = CrmSalesTour::query()->create([
            'site_id' => $site->id,
            'representative_user_id' => $crmUser->id,
            'title' => 'Tournee archive',
            'tour_date' => '2023-07-08',
            'status' => CrmSalesTour::STATUS_COMPLETED,
            'completed_at' => '2023-07-08 17:00:00',
            'created_by' => $crmUser->id,
        ]);
        CrmSalesVisit::query()->create([
            'tour_id' => $tour->id,
            'site_id' => $site->id,
            'representative_user_id' => $crmUser->id,
            'customer_name' => 'Client Visite',
            'planned_at' => '2023-07-08 09:00:00',
            'duration_minutes' => 45,
            'visit_type' => 'client',
            'priority' => 'normal',
            'status' => CrmSalesVisit::STATUS_DONE,
            'created_by' => $crmUser->id,
        ]);

        $this->artisan('crm:archive', ['--years' => 2])
            ->assertSuccessful();

        $this->assertDatabaseMissing('crm_leave_entries', ['id' => $leave->id]);
        $this->assertDatabaseMissing('crm_leave_transactions', ['entry_id' => $leave->id]);
        $this->assertDatabaseMissing('crm_leave_status_histories', ['entry_id' => $leave->id]);
        $this->assertDatabaseMissing('crm_cash_register_days', ['id' => $cashDay->id]);
        $this->assertDatabaseMissing('crm_cash_movements', ['cash_register_day_id' => $cashDay->id]);
        $this->assertDatabaseMissing('crm_cash_receipts', ['cash_register_day_id' => $cashDay->id]);
        $this->assertDatabaseMissing('crm_cash_count_lines', ['cash_register_day_id' => $cashDay->id]);
        $this->assertDatabaseMissing('crm_check_remittances', ['id' => $remittance->id]);
        $this->assertDatabaseMissing('crm_check_remittance_lines', ['check_remittance_id' => $remittance->id]);
        $this->assertDatabaseMissing('crm_deposit_requests', ['id' => $deposit->id]);
        $this->assertDatabaseMissing('crm_sales_tours', ['id' => $tour->id]);
        $this->assertDatabaseMissing('crm_sales_visits', ['tour_id' => $tour->id]);

        $this->assertArchiveRelationCount('crm_archived_leave_entries', $leave->id, 'transactions', 1);
        $this->assertArchiveRelationCount('crm_archived_leave_entries', $leave->id, 'statusHistories', 1);
        $this->assertArchiveRelationCount('crm_archived_cash_register_days', $cashDay->id, 'movements', 1);
        $this->assertArchiveRelationCount('crm_archived_cash_register_days', $cashDay->id, 'receipts', 1);
        $this->assertArchiveRelationCount('crm_archived_cash_register_days', $cashDay->id, 'cashCountLines', 1);
        $this->assertArchiveRelationCount('crm_archived_check_remittances', $remittance->id, 'checks', 1);
        $this->assertArchiveRelationCount('crm_archived_sales_tours', $tour->id, 'visits', 1);
        $this->assertDatabaseHas('crm_archived_deposit_requests', ['original_id' => $deposit->id]);
    }

    private function assertArchiveRelationCount(string $archiveTable, int $originalId, string $relation, int $count): void
    {
        $archive = DB::table($archiveTable)
            ->where('original_id', $originalId)
            ->first();

        $this->assertNotNull($archive);

        $data = json_decode((string) $archive->data, true);

        $this->assertCount($count, $data['_relations'][$relation] ?? []);
    }

    /**
     * @return array{0: CrmSite, 1: CrmUser, 2: CrmVehicle, 3: CrmEquipmentItem}
     */
    private function archiveFixtures(): array
    {
        $site = CrmSite::query()->create([
            'name' => 'Palissy Archive',
            'slug' => 'palissy-archive',
            'active' => true,
            'morning_start' => '07:30:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:30:00',
            'afternoon_end' => '17:30:00',
        ]);
        $crmUser = CrmUser::query()->create([
            'name' => 'Archive User',
            'role' => 'responsable',
            'active' => true,
        ]);
        $vehicle = CrmVehicle::query()->create([
            'site_id' => $site->id,
            'name' => 'Sprinter Archive',
            'description' => '',
            'color' => '#95002e',
            'active' => true,
        ]);
        $category = CrmEquipmentCategory::query()->create([
            'name' => 'Archive Materiel',
            'slug' => 'archive-materiel',
            'active' => true,
            'sort_order' => 10,
        ]);
        $item = CrmEquipmentItem::query()->create([
            'site_id' => $site->id,
            'category_id' => $category->id,
            'name' => 'Ponceuse Archive',
            'inventory_code' => 'ARCH-1',
            'description' => '',
            'color' => '#95002e',
            'half_day_price' => 45,
            'day_price' => 80,
            'deposit_amount' => 300,
            'active' => true,
            'sort_order' => 10,
        ]);

        return [$site, $crmUser, $vehicle, $item];
    }
}
