<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->boolean('is_active')->default(true)->after('is_admin');
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('base_url');
            $table->json('listing_urls');
            $table->string('adapter_class');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('refresh_interval_minutes')->default(180);
            $table->unsignedInteger('max_pages_per_run')->default(3);
            $table->unsignedInteger('max_requests_per_run')->default(30);
            $table->unsignedInteger('rate_limit_ms')->default(1500);
            $table->json('fields_available')->nullable();
            $table->text('capability_notes')->nullable();
            $table->boolean('shared_hosting_ok')->default(true);
            $table->string('health_status', 32)->default('unknown'); // ok|degraded|error|blocked|unknown
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_parse_success_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->unsignedInteger('listings_found_last_run')->default(0);
            $table->text('last_error')->nullable();
            $table->string('last_error_type', 32)->nullable(); // http|parse|timeout|blocked|lock
            $table->timestamps();
        });

        Schema::create('sim_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 11)->unique(); // 0912xxxxxxx
            $table->char('code_digit', 1);
            $table->json('pattern_flags')->nullable();
            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sim_number_id')->constrained('sim_numbers')->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('canonical_url', 500);
            $table->unsignedBigInteger('price_amount')->nullable(); // toman
            $table->string('price_raw', 255)->nullable();
            $table->string('price_type', 32)->default('unknown');
            $table->string('condition_raw', 255)->nullable();
            $table->string('condition_normalized', 32)->default('unknown');
            $table->string('city', 100)->nullable();
            $table->string('city_raw', 255)->nullable();
            $table->string('seller_key', 191)->nullable();
            $table->string('availability', 32)->default('available'); // available|sold|not_observed|unreachable
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('last_checked_at');
            $table->timestamps();

            $table->unique(['source_id', 'external_id']);
            $table->index(['sim_number_id', 'availability']);
            $table->index(['price_amount']);
            $table->index(['last_seen_at']);
            $table->index(['condition_normalized']);
        });

        Schema::create('offer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('phone_type', 16)->default('mobile');
            $table->string('scope', 16)->default('store'); // listing|seller|store
            $table->string('label', 100)->nullable();
            $table->timestamps();

            $table->unique(['offer_id', 'phone', 'scope']);
        });

        Schema::create('price_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->unsignedBigInteger('price_amount')->nullable();
            $table->string('price_raw', 255)->nullable();
            $table->string('price_type', 32)->default('unknown');
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['offer_id', 'observed_at']);
        });

        Schema::create('crawl_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('pending'); // pending|running|success|partial|failed
            $table->string('trigger', 32)->default('cron'); // cron|manual|audit
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('pages_fetched')->default(0);
            $table->unsignedInteger('requests_made')->default(0);
            $table->unsignedInteger('listings_upserted')->default(0);
            $table->unsignedInteger('listings_parsed')->default(0);
            $table->text('error')->nullable();
            $table->json('progress')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'status']);
        });

        Schema::create('crawl_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete()->unique();
            $table->json('cursor')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });

        Schema::create('team_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sim_number_id')->constrained('sim_numbers')->cascadeOnDelete();
            $table->string('deal_type', 16); // buy|sell
            $table->unsignedBigInteger('amount_toman');
            $table->date('deal_date');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['sim_number_id', 'deal_date']);
        });

        Schema::create('source_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain');
            $table->date('audit_date');
            $table->string('status', 64);
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_audits');
        Schema::dropIfExists('team_deals');
        Schema::dropIfExists('crawl_checkpoints');
        Schema::dropIfExists('crawl_runs');
        Schema::dropIfExists('price_observations');
        Schema::dropIfExists('offer_contacts');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('sim_numbers');
        Schema::dropIfExists('sources');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'is_active']);
        });
    }
};
