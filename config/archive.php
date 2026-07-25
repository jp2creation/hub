<?php

use App\Enums\CrmLeaveStatus;
use App\Models\CrmCashRegisterDay;
use App\Models\CrmCheckRemittance;
use App\Models\CrmDepositRequest;
use App\Models\CrmEquipmentRental;
use App\Models\CrmLeaveEntry;
use App\Models\CrmReservation;
use App\Models\CrmSalesTour;

return [
    'defaults' => [
        'retention_years' => max(1, (int) env('CRM_ARCHIVE_RETENTION_YEARS', 2)),
        'batch_size' => max(1, (int) env('CRM_ARCHIVE_BATCH_SIZE', 500)),
    ],

    'models' => [
        CrmReservation::class => [
            'key' => 'reservations',
            'label' => 'Reservations vehicules',
            'archive_table' => 'crm_archived_reservations',
            'date_column' => 'end_at',
            'start_column' => 'start_at',
            'retention_years' => max(1, (int) env('CRM_ARCHIVE_RESERVATIONS_RETENTION_YEARS', 2)),
        ],

        CrmEquipmentRental::class => [
            'key' => 'equipment_rentals',
            'label' => 'Locations materiel',
            'archive_table' => 'crm_archived_equipment_rentals',
            'date_column' => 'end_at',
            'start_column' => 'start_at',
            'retention_years' => max(1, (int) env('CRM_ARCHIVE_EQUIPMENT_RENTALS_RETENTION_YEARS', 2)),
            'conditions' => [
                'status' => [
                    CrmEquipmentRental::STATUS_RETURNED,
                    CrmEquipmentRental::STATUS_CANCELLED,
                ],
            ],
        ],

        CrmLeaveEntry::class => [
            'key' => 'leave_entries',
            'label' => 'Congés & Absences',
            'archive_table' => 'crm_archived_leave_entries',
            'date_column' => 'end_date',
            'start_column' => 'start_date',
            'date_mode' => 'date',
            'retention_years' => max(1, (int) env('CRM_ARCHIVE_LEAVES_RETENTION_YEARS', 1)),
            'conditions' => [
                'status' => [
                    CrmLeaveStatus::Approved->value,
                    CrmLeaveStatus::Refused->value,
                ],
            ],
            'relations' => [
                'transactions',
                'statusHistories',
            ],
            'delete_children' => [
                ['table' => 'crm_leave_transactions', 'foreign_key' => 'entry_id'],
                ['table' => 'crm_leave_status_histories', 'foreign_key' => 'entry_id'],
            ],
        ],

        CrmCashRegisterDay::class => [
            'key' => 'cash_register_days',
            'label' => 'Journees de caisse',
            'archive_table' => 'crm_archived_cash_register_days',
            'date_column' => 'cash_date',
            'date_mode' => 'date',
            'retention_years' => max(1, (int) env('CRM_ARCHIVE_CASH_DAYS_RETENTION_YEARS', 3)),
            'relations' => [
                'movements',
                'receipts',
                'cashCountLines',
            ],
            'delete_children' => [
                ['table' => 'crm_cash_movements', 'foreign_key' => 'cash_register_day_id'],
                ['table' => 'crm_cash_receipts', 'foreign_key' => 'cash_register_day_id'],
                ['table' => 'crm_cash_count_lines', 'foreign_key' => 'cash_register_day_id'],
            ],
        ],

        CrmCheckRemittance::class => [
            'key' => 'check_remittances',
            'label' => 'Remises de cheques',
            'archive_table' => 'crm_archived_check_remittances',
            'date_column' => 'remittance_date',
            'date_mode' => 'date',
            'retention_years' => max(1, (int) env('CRM_ARCHIVE_CHECK_REMITTANCES_RETENTION_YEARS', 3)),
            'conditions' => [
                'status' => CrmCheckRemittance::STATUS_DEPOSITED,
            ],
            'relations' => [
                'checks',
            ],
            'delete_children' => [
                ['table' => 'crm_check_remittance_lines', 'foreign_key' => 'check_remittance_id'],
            ],
        ],

        CrmDepositRequest::class => [
            'key' => 'deposit_requests',
            'label' => 'Demandes acompte',
            'archive_table' => 'crm_archived_deposit_requests',
            'date_column' => 'request_date',
            'date_mode' => 'date',
            'retention_years' => max(1, (int) env('CRM_ARCHIVE_DEPOSIT_REQUESTS_RETENTION_YEARS', 3)),
            'conditions' => [
                'status' => CrmDepositRequest::STATUS_VALIDATED,
            ],
        ],

        CrmSalesTour::class => [
            'key' => 'sales_tours',
            'label' => 'Rapports de visite',
            'archive_table' => 'crm_archived_sales_tours',
            'date_column' => 'tour_date',
            'date_mode' => 'date',
            'retention_years' => max(1, (int) env('CRM_ARCHIVE_SALES_TOURS_RETENTION_YEARS', 5)),
            'conditions' => [
                'status' => [
                    CrmSalesTour::STATUS_COMPLETED,
                    CrmSalesTour::STATUS_CANCELED,
                ],
            ],
            'relations' => [
                'visits',
            ],
            'delete_children' => [
                ['table' => 'crm_sales_visits', 'foreign_key' => 'tour_id'],
            ],
        ],
    ],
];
