<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->string('attachable_type'); // invoice | bill
            $table->unsignedBigInteger('attachable_id');
            $table->string('original_name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_attachments');
    }
};
