<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CrmCore\Services\UploadedCrmFileCleaner;
use Modules\CrmCore\Support\CrmReferenceCache;

/**
 * @property int $id
 * @property bool $active
 * @property string|null $afternoon_end
 * @property string|null $afternoon_start
 * @property string|null $address
 * @property string $color
 * @property string|null $email
 * @property string|null $morning_end
 * @property string|null $morning_start
 * @property string $name
 * @property string|null $phone
 * @property string|null $photo_url
 * @property string|null $slug
 * @property-read object{is_default?: mixed}|null $pivot
 * @property-read Collection<int, CrmVehicle> $vehicles
 * @property-read Collection<int, CrmReservation> $reservations
 * @property-read Collection<int, CrmEquipmentItem> $equipmentItems
 * @property-read Collection<int, CrmEquipmentRental> $equipmentRentals
 * @property-read Collection<int, CrmCashRegisterDay> $cashRegisterDays
 * @property-read Collection<int, CrmUser> $users
 * @property-read Collection<int, CrmUserSiteModulePermission> $siteModulePermissions
 */
class CrmSite extends Model
{
    use SoftDeletes;

    protected $table = 'crm_sites';

    protected $fillable = [
        'name',
        'slug',
        'active',
        'morning_start',
        'morning_end',
        'afternoon_start',
        'afternoon_end',
        'address',
        'phone',
        'email',
        'color',
        'photo_url',
        'deleted_at',
    ];

    protected $attributes = [
        'color' => '#95002e',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CrmSite $site): void {
            if (blank($site->slug) || $site->isDirty('name')) {
                $site->slug = static::uniqueSlug($site->name, $site->getKey());
            }
        });

        static::deleting(function (CrmSite $site): void {
            $site->users()->detach();
            $site->siteModulePermissions()->delete();

            if (! $site->isForceDeleting()) {
                $site->forceFill([
                    'name' => Str::limit((string) $site->name, 100, '').' supprime '.$site->getKey(),
                    'slug' => Str::limit((string) ($site->slug ?: Str::slug($site->name)), 110, '').'-deleted-'.$site->getKey(),
                    'active' => false,
                ])->saveQuietly();

                return;
            }

            if (
                $site->vehicles()->exists()
                || $site->reservations()->exists()
                || $site->equipmentItems()->exists()
                || $site->equipmentRentals()->exists()
                || $site->cashRegisterDays()->exists()
            ) {
                throw ValidationException::withMessages([
                    'site' => 'Ce site est utilise par des vehicules, du materiel, des reservations ou des caisses. Supprime-le sans forcer pour l archiver.',
                ]);
            }
        });

        static::saved(function (): void {
            CrmReferenceCache::forgetSites();
            CrmReferenceCache::forgetUsers();
        });

        static::updated(function (CrmSite $site): void {
            if ($site->wasChanged('photo_url')) {
                app(UploadedCrmFileCleaner::class)->deletePublicUpload($site->getOriginal('photo_url'));
            }

            if ($site->wasChanged('active') && ! $site->active) {
                app(UploadedCrmFileCleaner::class)->deletePublicUpload($site->getAttribute('photo_url'));
            }
        });

        static::deleted(function (CrmSite $site): void {
            app(UploadedCrmFileCleaner::class)->deletePublicUpload($site->getAttribute('photo_url'));
            CrmReferenceCache::forgetSites();
            CrmReferenceCache::forgetUsers();
        });
    }

    public static function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'site';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return HasMany<CrmVehicle, $this>
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(CrmVehicle::class, 'site_id');
    }

    /**
     * @return HasMany<CrmReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(CrmReservation::class, 'site_id');
    }

    /**
     * @return HasMany<CrmEquipmentItem, $this>
     */
    public function equipmentItems(): HasMany
    {
        return $this->hasMany(CrmEquipmentItem::class, 'site_id');
    }

    /**
     * @return HasMany<CrmEquipmentRental, $this>
     */
    public function equipmentRentals(): HasMany
    {
        return $this->hasMany(CrmEquipmentRental::class, 'site_id');
    }

    /**
     * @return HasMany<CrmCashRegisterDay, $this>
     */
    public function cashRegisterDays(): HasMany
    {
        return $this->hasMany(CrmCashRegisterDay::class, 'site_id');
    }

    /**
     * @return BelongsToMany<CrmUser, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(CrmUser::class, 'crm_user_sites', 'site_id', 'user_id')
            ->withPivot('is_default');
    }

    /**
     * @return HasMany<CrmUserSiteModulePermission, $this>
     */
    public function siteModulePermissions(): HasMany
    {
        return $this->hasMany(CrmUserSiteModulePermission::class, 'site_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public static function options(): array
    {
        return static::query()
            ->orderByDesc('active')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function openingHoursLabel(): string
    {
        return sprintf(
            '%s-%s / %s-%s',
            substr((string) ($this->morning_start ?: '07:30'), 0, 5),
            substr((string) ($this->morning_end ?: '12:00'), 0, 5),
            substr((string) ($this->afternoon_start ?: '13:30'), 0, 5),
            substr((string) ($this->afternoon_end ?: '17:30'), 0, 5),
        );
    }

    public function containsOpeningPeriod(mixed $startAt, mixed $endAt): bool
    {
        $start = $startAt instanceof Carbon ? $startAt : Carbon::parse($startAt);
        $end = $endAt instanceof Carbon ? $endAt : Carbon::parse($endAt);
        $startMinute = ($start->hour * 60) + $start->minute;
        $endMinute = ($end->hour * 60) + $end->minute;
        $morningStart = self::minutes($this->morning_start, '07:30');
        $morningEnd = self::minutes($this->morning_end, '12:00');
        $afternoonStart = self::minutes($this->afternoon_start, '13:30');
        $afternoonEnd = self::minutes($this->afternoon_end, '17:30');

        $startAllowed = ($startMinute >= $morningStart && $startMinute < $morningEnd)
            || ($startMinute >= $afternoonStart && $startMinute < $afternoonEnd);
        $endAllowed = ($endMinute > $morningStart && $endMinute <= $morningEnd)
            || ($endMinute > $afternoonStart && $endMinute <= $afternoonEnd);

        return $startAllowed && $endAllowed && $startMinute >= $morningStart && $endMinute <= $afternoonEnd;
    }

    private static function minutes(?string $time, string $default): int
    {
        $time = substr((string) ($time ?: $default), 0, 5);
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }
}
