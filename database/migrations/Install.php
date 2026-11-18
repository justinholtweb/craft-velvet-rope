<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier's schema, prefixed and hung off Velvet Rope's own `members` table so
 * Craft's users table is never altered. Column names match Cashier's migrations.
 */
class Install extends Migration
{
    public function up(): void
    {
        Schema::create('velvetrope_members', function (Blueprint $table) {
            $table->id();
            // Craft's users.id is a signed int (not Laravel's bigint unsigned), so no foreignId().
            $table->integer('userId')->unique();
            $table->foreign('userId')->references('id')->on(Table::USERS)->cascadeOnDelete();
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('velvetrope_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('velvetrope_members')->cascadeOnDelete();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // Velvet Rope: when Stripe first reported the subscription past_due (drives the grace period).
            $table->timestamp('past_due_since')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'stripe_status']);
        });

        Schema::create('velvetrope_subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('velvetrope_subscriptions')->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();
            $table->index(['subscription_id', 'stripe_price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('velvetrope_subscription_items');
        Schema::dropIfExists('velvetrope_subscriptions');
        Schema::dropIfExists('velvetrope_members');
    }
}
