<?php

namespace App\Models;

use App\Models\Builders\CrmUserBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\CrmCore\Services\UploadedCrmFileCleaner;
use Modules\CrmCore\Support\CrmReferenceCache;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $bio
 * @property string|null $photo_url
 * @property string $role
 * @property bool $active
 * @property-read User|null $account
 * @property-read Collection<int, CrmSite> $sites
 * @property-read Collection<int, CrmModule> $modules
 * @property-read Collection<int, CrmPermission> $permissions
 * @property-read Collection<int, CrmUserSiteModulePermission> $siteModulePermissions
 * @property-read Collection<int, CrmReservation> $reservations
 * @property-read Collection<int, CrmEquipmentRental> $equipmentRentals
 * @property-read Collection<int, CrmCashRegisterDay> $createdCashRegisterDays
 * @property-read Collection<int, CrmCashMovement> $cashMovementsUploads
 */
class CrmUser extends User
{
    protected $table = 'users';

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'bio',
        'photo_url',
        'role',
        'active',
        'password',
    ];

    protected $attributes = [
        'role' => 'user',
        'active' => true,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CrmUser $user): void {
            if (blank($user->email)) {
                $user->email = self::generatedEmail($user->name);
            }

            if (blank($user->password)) {
                $user->password = Str::random(48);
            }

            if (blank($user->role)) {
                $user->role = 'user';
            }
        });

        static::saved(function (CrmUser $user): void {
            $user->ensureLegacyConstraintRow();

            if ($user->wasChanged('name')) {
                $user->reservations()->update(['user_name' => $user->name]);
                $user->equipmentRentals()->update(['user_name' => $user->name]);
            }

            if ($user->wasChanged('photo_url')) {
                app(UploadedCrmFileCleaner::class)->deletePublicUpload($user->getOriginal('photo_url'));
            }

            CrmReferenceCache::forgetUsers();
        });

        static::deleting(function (CrmUser $user): void {
            $user->sites()->detach();
            $user->modules()->detach();
            $user->permissions()->detach();
            $user->siteModulePermissions()->delete();
        });

        static::deleted(function (CrmUser $user): void {
            app(UploadedCrmFileCleaner::class)->deletePublicUpload($user->getAttribute('photo_url'));
            CrmReferenceCache::forgetUsers();
        });
    }

    public static function roleOptions(): array
    {
        return [
            'admin' => 'Administrateur',
            'responsable' => 'Responsable site',
            'user' => 'Employe',
            'blocked' => 'Sans acces',
        ];
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): CrmUserBuilder
    {
        return new CrmUserBuilder($query);
    }

    public function scopeForAccount(Builder $query, User $user): Builder
    {
        return $query->whereKey($user->id);
    }

    public function getUserIdAttribute(): int
    {
        return (int) $this->getKey();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id', 'id');
    }

    /**
     * @return BelongsToMany<CrmSite, $this>
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(CrmSite::class, 'crm_user_sites', 'user_id', 'site_id')
            ->withPivot('is_default');
    }

    /**
     * @return BelongsToMany<CrmModule, $this>
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(CrmModule::class, 'crm_user_modules', 'user_id', 'module_id');
    }

    /**
     * @return BelongsToMany<CrmPermission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(CrmPermission::class, 'crm_user_permissions', 'user_id', 'permission_id');
    }

    /**
     * @return HasMany<CrmUserSiteModulePermission, $this>
     */
    public function siteModulePermissions(): HasMany
    {
        return $this->hasMany(CrmUserSiteModulePermission::class, 'user_id');
    }

    /**
     * @return HasMany<CrmReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(CrmReservation::class, 'user_id');
    }

    /**
     * @return HasMany<CrmEquipmentRental, $this>
     */
    public function equipmentRentals(): HasMany
    {
        return $this->hasMany(CrmEquipmentRental::class, 'user_id');
    }

    /**
     * @return HasMany<CrmCashRegisterDay, $this>
     */
    public function createdCashRegisterDays(): HasMany
    {
        return $this->hasMany(CrmCashRegisterDay::class, 'created_by');
    }

    /**
     * @return HasMany<CrmCashMovement, $this>
     */
    public function cashMovementsUploads(): HasMany
    {
        return $this->hasMany(CrmCashMovement::class, 'uploaded_by');
    }

    private static function generatedEmail(?string $name): string
    {
        $base = Str::slug((string) $name) ?: 'hub-user';
        $email = "{$base}@hub.local";
        $suffix = 1;

        while (static::query()->where('email', $email)->exists()) {
            $email = "{$base}-{$suffix}@hub.local";
            $suffix++;
        }

        return $email;
    }

    private function ensureLegacyConstraintRow(): void
    {
        if (DB::connection()->getDriverName() === 'mysql' || ! Schema::hasTable('crm_users')) {
            return;
        }

        if (DB::table('crm_users')->where('id', $this->id)->exists()) {
            return;
        }

        DB::table('crm_users')->insert([
            'id' => (int) $this->id,
            'name' => $this->legacyConstraintName(),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => null,
            'bio' => $this->bio,
            'photo_url' => $this->photo_url,
            'role' => $this->role ?: 'user',
            'active' => (bool) $this->active,
            'user_id' => null,
            'phone' => $this->phone,
            'created_at' => $this->created_at ?? now(),
            'updated_at' => $this->updated_at ?? now(),
        ]);
    }

    private function legacyConstraintName(): string
    {
        $name = "legacy-hub-user-{$this->id}";
        $suffix = 1;

        while (DB::table('crm_users')->where('name', $name)->exists()) {
            $name = "legacy-hub-user-{$this->id}-{$suffix}";
            $suffix++;
        }

        return $name;
    }
}
