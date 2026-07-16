<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    App\Modules\Hr\Providers\HrServiceProvider::class,               // HR module
    App\Modules\Projects\Providers\ProjectsServiceProvider::class,   // Projects (PMS) module
    App\Modules\Crm\Providers\CrmServiceProvider::class,             // CRM (Zerem) module
];
