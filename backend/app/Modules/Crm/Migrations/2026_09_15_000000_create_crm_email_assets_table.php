<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email assets: images an organization's designed emails show — for now its logo.
 *
 * An asset is IMMUTABLE once stored. Replacing the organization logo stores a new asset and points the
 * branding at it (crm_email_brandings), so an email that was already sent keeps showing the image it was sent
 * with. Every asset has a PNG `path`: the file every email client is given, re-encoded on the server. An SVG
 * upload also keeps its sanitized, cropped SVG at `svg_path` — never served to email clients, which do not
 * render SVG reliably.
 *
 * `uuid` is the public identity: the unauthenticated email-asset route serves the PNG by it, and it is the
 * only identifier that ever appears in a rendered email. A design never stores it — the logo block resolves
 * through the organization's branding. Files live on the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_email_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('purpose', 20);
            $table->string('source_format', 10);
            $table->string('path');
            $table->string('svg_path')->nullable();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64);
            $table->json('meta');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_assets');
    }
};
