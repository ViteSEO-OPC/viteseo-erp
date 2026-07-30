#!/usr/bin/env bash
# Local helper for trying out geofenced attendance. NOT part of the app — delete freely.
#
#   ./geofence-demo.sh status     what the policy says + the employee's last punch
#   ./geofence-demo.sh reset      clear today's punch + any selfies, so you can retry
#   ./geofence-demo.sh on         turn the geofence on  (office IP = 127.0.0.1)
#   ./geofence-demo.sh off        turn it off (the kill switch)
#   ./geofence-demo.sh break-ip   set a WRONG office IP, to force the Office path to fail
set -u
cd "$(dirname "$0")"

case "${1:-status}" in
  status)
    php artisan tinker --execute="
      \$p = app(App\Modules\Hr\Services\AttendancePolicyService::class)->get();
      echo 'geofence : '.(\$p->geofence_enabled ? 'ON' : 'off').PHP_EOL;
      echo 'office   : '.(\$p->office_lat ?? '(no pin)').', '.(\$p->office_lng ?? '').'  radius '.\$p->office_radius_m.'m'.PHP_EOL;
      echo 'allowed  : '.json_encode(\$p->office_ips).PHP_EOL;
      \$e = App\Models\User::where('email','employee@viteseo.ph')->first()->employee;
      \$a = App\Modules\Hr\Models\Attendance::where('employee_id',\$e->id)
             ->where('work_date', now(config('app.timezone_local'))->toDateString())->first();
      echo 'today    : '.(\$a && \$a->time_in
            ? 'in at '.\$a->time_in->timezone(config('app.timezone_local'))->format('g:i A')
              .'  status='.\$a->status.'  verified_by='.(\$a->location_verified_by ?? 'null')
            : 'not clocked in').PHP_EOL;
      \$c = App\Modules\Hr\Models\RemoteCheckin::where('employee_id',\$e->id)->latest()->first();
      echo 'selfie   : '.(\$c ? \$c->status.' (sent '.\$c->created_at->timezone(config('app.timezone_local'))->format('g:i A').')' : 'none').PHP_EOL;
    " 2>/dev/null | grep -E "geofence|office|allowed|today|selfie"
    ;;
  reset)
    php artisan tinker --execute="
      \$e = App\Models\User::where('email','employee@viteseo.ph')->first()->employee;
      App\Modules\Hr\Models\Attendance::withTrashed()->where('employee_id',\$e->id)
        ->where('work_date', now(config('app.timezone_local'))->toDateString())->forceDelete();
      App\Modules\Hr\Models\RemoteCheckin::withTrashed()->where('employee_id',\$e->id)->forceDelete();
    " >/dev/null 2>&1
    echo "Cleared today's punch and any selfies for employee@viteseo.ph."
    ;;
  on|off|break-ip)
    case "$1" in
      on)       SET="'geofence_enabled' => true,  'office_ips' => ['127.0.0.1']" ;;
      off)      SET="'geofence_enabled' => false" ;;
      break-ip) SET="'geofence_enabled' => true,  'office_ips' => ['203.0.113.10']" ;;
    esac
    php artisan tinker --execute="
      app(App\Modules\Hr\Services\AttendancePolicyService::class)->update([$SET]);
    " >/dev/null 2>&1
    echo "Done — run './geofence-demo.sh status' to confirm."
    ;;
  *) echo "Unknown command '$1'. Try: status | reset | on | off | break-ip" ;;
esac
