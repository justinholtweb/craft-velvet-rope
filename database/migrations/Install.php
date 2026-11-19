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
            // Velvet Rope: the price as Stripe last reported it (drives MRR in the CP).
            $table->integer('amount')->nullable(); // smallest currency unit, per interval, before discounts
            $table->string('currency', 3)->nullable();
            $table->string('interval', 8)->nullable(); // day, week, month, year
            $table->smallInteger('interval_count')->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->integer('discount_amount')->nullable(); // per interval, same currency
            // Velvet Rope Pro dunning: 0 none, 1 payment failed, 2 grace ending, 3 access ended.
            $table->tinyInteger('dunning_stage')->default(0);
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

        // Access that isn't a Stripe subscription: redeemed gifts and complimentary grants (Pro).
        Schema::create('velvetrope_grants', function (Blueprint $table) {
            $table->id();
            $table->integer('userId');
            $table->foreign('userId')->references('id')->on(Table::USERS)->cascadeOnDelete();
            $table->string('plan', 64);
            $table->string('source', 16); // gift, comp
            $table->unsignedBigInteger('giftId')->nullable();
            $table->timestamp('startsAt');
            $table->timestamp('endsAt');
            $table->string('note')->nullable();
            $table->integer('createdById')->nullable();
            $table->timestamps();
            $table->index(['userId', 'plan', 'endsAt']);
        });

        // Gift memberships bought through Stripe Checkout (Pro). Only a hash of the code is kept.
        Schema::create('velvetrope_gifts', function (Blueprint $table) {
            $table->id();
            $table->char('codeHash', 64)->unique();
            $table->string('option', 64);
            $table->string('plan', 64);
            $table->smallInteger('months');
            $table->string('buyerEmail');
            $table->string('stripeSessionId')->unique();
            $table->string('siteHandle', 64)->nullable();
            $table->integer('redeemedByUserId')->nullable();
            $table->timestamp('redeemedAt')->nullable();
            $table->timestamps();
        });

        // Checkouts started by people who weren't logged in, and what became of each.
        Schema::create('velvetrope_guest_checkouts', function (Blueprint $table) {
            $table->id();
            $table->string('stripeSessionId')->unique();
            $table->string('email');
            $table->string('plan', 64);
            $table->string('stripeCustomerId')->nullable();
            $table->string('stripeSubscriptionId')->nullable();
            $table->integer('userId')->nullable();
            $table->string('outcome', 16); // created, attached, review
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('velvetrope_guest_checkouts');
        Schema::dropIfExists('velvetrope_gifts');
        Schema::dropIfExists('velvetrope_grants');
        Schema::dropIfExists('velvetrope_subscription_items');
        Schema::dropIfExists('velvetrope_subscriptions');
        Schema::dropIfExists('velvetrope_members');
    }
}
