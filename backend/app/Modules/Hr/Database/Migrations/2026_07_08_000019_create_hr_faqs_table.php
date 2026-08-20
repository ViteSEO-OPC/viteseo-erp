<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2. Self-service FAQ / knowledge base. HR authors; employees read
// the published entries. status: draft → published → archived.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->text('answer');
            $table->string('category');
            $table->json('tags')->nullable();
            $table->string('status')->default('draft'); // draft | published | archived
            $table->boolean('is_pinned')->default(false);
            $table->unsignedInteger('view_count')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_faqs');
    }
};
