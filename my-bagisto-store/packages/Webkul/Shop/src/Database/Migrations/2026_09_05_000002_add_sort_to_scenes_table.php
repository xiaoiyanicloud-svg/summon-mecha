<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * scenes 表加「权重 sort」：值越大在「全部场景」列表越靠前，默认 0。后台可编辑。
     */
    public function up()
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->integer('sort')->default(0)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn('sort');
        });
    }
};
