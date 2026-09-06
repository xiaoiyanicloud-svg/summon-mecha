<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 给 scenes 表增加「方案内容」结构化字段：
     * icon/bg_color/consult_count 为单值；tags/stats/pains/benefits/scheme_scenes/robots 为 JSON 数组。
     * scheme_scenes 列名避开表名 scenes（避免与 Eloquent 混淆）。
     */
    public function up()
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->string('icon')->nullable()->after('status');
            $table->string('bg_color')->nullable()->after('icon');
            $table->integer('consult_count')->default(0)->after('bg_color');
            $table->json('tags')->nullable()->after('consult_count');
            $table->json('stats')->nullable()->after('tags');
            $table->json('pains')->nullable()->after('stats');
            $table->json('benefits')->nullable()->after('pains');
            $table->json('scheme_scenes')->nullable()->after('benefits');
            $table->json('robots')->nullable()->after('scheme_scenes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn([
                'icon', 'bg_color', 'consult_count', 'tags', 'stats',
                'pains', 'benefits', 'scheme_scenes', 'robots',
            ]);
        });
    }
};
