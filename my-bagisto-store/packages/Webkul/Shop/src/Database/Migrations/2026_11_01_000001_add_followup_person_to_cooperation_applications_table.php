<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cooperation_applications')) {
            return;
        }

        Schema::table('cooperation_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('cooperation_applications', 'followup_person')) {
                $table->string('followup_person', 64)->nullable()->after('status')->comment('商务跟进人员/负责人姓名或工号');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cooperation_applications')) {
            return;
        }

        Schema::table('cooperation_applications', function (Blueprint $table) {
            if (Schema::hasColumn('cooperation_applications', 'followup_person')) {
                $table->dropColumn('followup_person');
            }
        });
    }
};
