<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * scenes 表加「热门 is_hot」：设为热门的场景显示在首页「场景方案」。默认 0。
     */
    public function up()
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->boolean('is_hot')->default(0)->after('sort');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn('is_hot');
        });
    }
};
