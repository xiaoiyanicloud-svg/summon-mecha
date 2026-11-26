<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deploy_task')) {
            Schema::table('deploy_task', function (Blueprint $table) {
                if (! Schema::hasColumn('deploy_task', 'delivery_attempt')) {
                    $table->unsignedInteger('delivery_attempt')->default(0)->after('command_seq');
                }
                if (! Schema::hasColumn('deploy_task', 'outbox_lease_token')) {
                    $table->string('outbox_lease_token', 64)->nullable()->after('delivery_attempt');
                }
                if (! Schema::hasColumn('deploy_task', 'outbox_lease_expires_at')) {
                    $table->timestamp('outbox_lease_expires_at')->nullable()->after('outbox_lease_token');
                    $table->index('outbox_lease_expires_at', 'idx_deploy_outbox_lease_expiry');
                }
            });
        }

        if (! Schema::hasTable('deploy_inbox_event')) {
            Schema::create('deploy_inbox_event', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('did', 64);
                $table->string('event_id', 64);
                $table->string('task_no', 40);
                $table->string('event_type', 32)->default('ack');
                $table->string('stage', 32);
                $table->string('payload_hash', 64);
                $table->json('payload')->nullable();
                $table->string('ack_id', 64)->unique('uk_deploy_inbox_ack_id');
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->unique(['did', 'event_id'], 'uk_deploy_inbox_did_event');
                $table->index(['task_no', 'stage'], 'idx_deploy_inbox_task_stage');
            });
        }

        if (Schema::hasTable('skill_target_releases')) {
            Schema::table('skill_target_releases', function (Blueprint $table) {
                if (! Schema::hasColumn('skill_target_releases', 'target_profile_digest')) {
                    $table->string('target_profile_digest', 128)->nullable()->after('build_key');
                }
                if (! Schema::hasColumn('skill_target_releases', 'source_digest')) {
                    $table->string('source_digest', 128)->nullable()->after('target_profile_digest');
                }
            });
        }

        if (! Schema::hasTable('skill_target_release_events')) {
            Schema::create('skill_target_release_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('skill_version_id');
                $table->string('target_model_code', 64);
                $table->unsignedInteger('artifact_asset_id');
                $table->string('build_key', 128);
                $table->string('target_profile_digest', 128);
                $table->string('event_type', 32);
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('reason', 512)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['skill_version_id', 'target_model_code', 'id'], 'idx_release_events_version_model');
            });
        }

        if (Schema::hasTable('device_model')) {
            DB::table('device_model')
                ->where('model_code', 'agibot_a2')
                ->update([
                    'status' => 'display_only',
                    'comm_channels' => json_encode(['manual']),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_target_release_events');
        Schema::dropIfExists('deploy_inbox_event');

        if (Schema::hasTable('skill_target_releases')) {
            Schema::table('skill_target_releases', function (Blueprint $table) {
                foreach (['target_profile_digest', 'source_digest'] as $column) {
                    if (Schema::hasColumn('skill_target_releases', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('deploy_task')) {
            Schema::table('deploy_task', function (Blueprint $table) {
                foreach (['outbox_lease_expires_at', 'outbox_lease_token', 'delivery_attempt'] as $column) {
                    if (Schema::hasColumn('deploy_task', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};