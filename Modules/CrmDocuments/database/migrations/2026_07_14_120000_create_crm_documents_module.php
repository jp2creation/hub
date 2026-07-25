<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_document_directories')) {
            Schema::create('crm_document_directories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('crm_sites')->cascadeOnDelete();
                $table->string('category', 60)->index();
                $table->foreignId('parent_id')->nullable()->constrained('crm_document_directories')->cascadeOnDelete();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->string('visibility', 30)->default('restricted')->index();
                $table->unsignedInteger('sort_order')->default(100);
                $table->foreignId('created_by')->nullable()->constrained('crm_users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('crm_users')->nullOnDelete();
                $table->timestamps();

                $table->index(['site_id', 'category', 'parent_id']);
            });
        }

        if (! Schema::hasTable('crm_documents')) {
            Schema::create('crm_documents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('crm_sites')->cascadeOnDelete();
                $table->string('category', 60)->index();
                $table->foreignId('directory_id')->nullable()->constrained('crm_document_directories')->nullOnDelete();
                $table->string('name', 180);
                $table->text('description')->nullable();
                $table->string('visibility', 30)->default('restricted')->index();
                $table->string('disk', 60)->default('local');
                $table->string('disk_path', 255)->unique();
                $table->string('original_name', 180)->nullable();
                $table->string('mime_type', 120)->default('application/octet-stream');
                $table->unsignedInteger('size')->default(0);
                $table->unsignedInteger('sort_order')->default(100);
                $table->foreignId('created_by')->nullable()->constrained('crm_users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('crm_users')->nullOnDelete();
                $table->timestamps();

                $table->index(['site_id', 'category', 'directory_id']);
            });
        }

        if (Schema::hasTable('crm_modules')) {
            foreach ([
                ['documents-promo', 'Promo', 'Documents commerciaux et promotions.', '/documents/promo', 241],
                ['documents-fiches-techniques', 'Fiches techniques', 'Fiches techniques produits et matériel.', '/documents/fiches-techniques', 242],
                ['documents-procedures', 'Procédures', 'Procédures internes du HUB.', '/documents/procedures', 243],
            ] as [$slug, $name, $description, $routePath, $sortOrder]) {
                DB::table('crm_modules')->updateOrInsert(
                    ['slug' => $slug],
                    [
                        'name' => $name,
                        'description' => $description,
                        'route_path' => $routePath,
                        'active' => true,
                        'sort_order' => $sortOrder,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }

        if (Schema::hasTable('crm_menu_items')) {
            foreach ([
                ['module:documents-promo', 'Promo', 10],
                ['module:documents-fiches-techniques', 'Fiches techniques', 20],
                ['module:documents-procedures', 'Procédures', 30],
            ] as [$itemKey, $label, $sortOrder]) {
                DB::table('crm_menu_items')->updateOrInsert(
                    ['item_key' => $itemKey],
                    [
                        'group_key' => 'apps',
                        'icon_key' => 'article',
                        'label' => $label,
                        'active' => true,
                        'sort_order' => $sortOrder,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }

        if (Schema::hasTable('crm_permissions')) {
            foreach ([
                ['documents.view', 'Voir les documents', 'Documents', 171],
                ['documents.manage', 'Gérer les documents', 'Documents', 172],
            ] as [$name, $label, $group, $sortOrder]) {
                DB::table('crm_permissions')->updateOrInsert(
                    ['name' => $name],
                    [
                        'label' => $label,
                        'group_label' => $group,
                        'sort_order' => $sortOrder,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('crm_menu_items')) {
            DB::table('crm_menu_items')->whereIn('item_key', [
                'module:documents-promo',
                'module:documents-fiches-techniques',
                'module:documents-procedures',
            ])->delete();
        }

        if (Schema::hasTable('crm_modules')) {
            DB::table('crm_modules')->whereIn('slug', [
                'documents-promo',
                'documents-fiches-techniques',
                'documents-procedures',
            ])->delete();
        }

        if (Schema::hasTable('crm_menu_groups')) {
            DB::table('crm_menu_groups')->where('menu_key', 'documents')->delete();
        }

        if (Schema::hasTable('crm_permissions')) {
            DB::table('crm_permissions')->whereIn('name', ['documents.view', 'documents.manage'])->delete();
        }

        Schema::dropIfExists('crm_documents');
        Schema::dropIfExists('crm_document_directories');
    }
};
