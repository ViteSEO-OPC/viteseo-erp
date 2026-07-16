<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expanded 201-file personal record for the employee view: split name, personal
// info, educational background, emergency-contact relationship, and health. The
// identity name/email still live on the linked users row; the split-name fields
// here are the source HR edits (name is composed from them).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            // Personal information
            $table->string('first_name')->nullable()->after('employee_no');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->string('name_extension')->nullable()->after('last_name'); // Jr., III …
            $table->string('gender')->nullable()->after('name_extension');
            $table->string('civil_status')->nullable()->after('gender');
            $table->string('nationality')->nullable()->after('civil_status');
            $table->date('date_of_birth')->nullable()->after('nationality');
            $table->string('place_of_birth')->nullable()->after('date_of_birth');
            $table->text('current_address')->nullable()->after('address'); // `address` = home address
            $table->string('mothers_maiden_name')->nullable()->after('current_address');
            $table->string('fathers_name')->nullable()->after('mothers_maiden_name');
            $table->string('siblings_name')->nullable()->after('fathers_name');
            // Educational background
            $table->string('high_school')->nullable()->after('siblings_name');
            $table->string('college_course')->nullable()->after('high_school');
            $table->string('university')->nullable()->after('college_course');
            // Emergency contact (name + phone already exist)
            $table->string('emergency_contact_relationship')->nullable()->after('emergency_contact_name');
            // Health
            $table->string('height')->nullable()->after('bank_account');
            $table->string('weight')->nullable()->after('height');
            $table->text('health_condition')->nullable()->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'middle_name', 'last_name', 'name_extension', 'gender',
                'civil_status', 'nationality', 'date_of_birth', 'place_of_birth',
                'current_address', 'mothers_maiden_name', 'fathers_name', 'siblings_name',
                'high_school', 'college_course', 'university',
                'emergency_contact_relationship', 'height', 'weight', 'health_condition',
            ]);
        });
    }
};
