<?php

use Carbon\Carbon;
use App\Models\Seo;
use App\Models\Theme;
use AmrShawky\Currency;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\Employee;
use Carbon\CarbonPeriod;
use App\Models\WorkingDay;
use Illuminate\Support\Str;
use msztorc\LaravelEnv\Env;
use Nexmo\Client as NexmoClient;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Vonage\Client\Credentials\Basic;
use Illuminate\Support\Facades\Artisan;
use Twilio\Rest\Client as TwilioClient;
use Stichoza\GoogleTranslate\GoogleTranslate;

function uploadFileToPublic(string $path, $file)
{
    if ($file && $path) {
        $url = $file->move('uploads/' . $path, $file->hashName());
    } else {
        $url = null;
    }

    return $url;
}

function currentUser()
{
    return auth()->user();
}

function currentUserRole()
{
    return auth()->user()->role;
}

function currentUserId()
{
    return auth()->id();
}

function currentCompany()
{
    return auth()->user()->company;
}

function currentEmployee()
{
    return auth()->user()->employee;
}

function getCompanyUserByEmployeeUser($user_id)
{
    $company_id = Employee::where('user_id', $user_id)->value('company_id');
    return Company::findOrFail($company_id)->user;
}

function getCompany($user_id)
{
    return Company::where('user_id', $user_id)->firstOrFail();
}

function getEmployee($user_id)
{
    return Employee::where('user_id', $user_id)->firstOrFail();
}

function getUserByCompanyId($id)
{
    return Company::where('id', $id)->firstOrFail()->user;
}

function getUserByEmployeeId($id)
{
    return Employee::where('id', $id)->firstOrFail()->user;
}

function strSlug($name)
{
    return Str::slug($name);
}

function redirect_to($name)
{
    return redirect()->route($name);
}

function addDays($date, $days, $format = 'Y-m-d')
{
    $date = Carbon::createFromFormat($format, $date);
    $daysToAdd = $days;
    return $date->addDays($daysToAdd)->format($format);
}

function formatDateTime($date, $format = 'Y-m-d')
{
    return Carbon::createFromFormat($format, $date);
}

function changeCurrentYear($date, $format = 'Y-m-d')
{
    return Carbon::parse($date)->year(now()->format('Y'))->format($format);
}

function getHolidays($country_code = 'bd')
{
    $api = config('kodebazar.google_api');
    $calendar_api = "https://www.googleapis.com/calendar/v3/calendars/en.$country_code%23holiday%40group.v.calendar.google.com/events?key=$api";

    $response = Http::get($calendar_api);
    $holidays_list = $response->json()['items'];

    $current_year_holidays = [];

    foreach ($holidays_list as $holiday) {
        if (currentYearData($holiday['start']['date'])) {
            $current_year_holidays[] = [
                'title' => $holiday['summary'],
                'start' => $holiday['start']['date'],
                'end' => subDays($holiday['end']['date'])
            ];
        }
    }

    return $current_year_holidays;
}

function currentYearData($data, $format = 'Y-m-d')
{
    $date = Carbon::createFromFormat($format, $data)->format('Y');

    return $date == now()->format('Y') ? 1 : 0;
}

function translations($json)
{
    if (!file_exists($json)) {
        return [];
    }
    return json_decode(file_get_contents($json), true);
}

function translateIt(String $text, $code)
{
    return GoogleTranslate::trans($text, $code, 'en');
}

function getAdminTheme()
{
    if (!session()->has('theme')) {
        $theme = Theme::first();
        session(['theme' => $theme]);
    }

    return session('theme');
}

function storeCompanyCurrentSubscription()
{
    session()->forget('current_subscription');

    if (auth()->check() && auth()->user()->role == 'company') {
        $subscription = currentCompany()->subscription->load(['plan' => function ($query) {
            $query->with('planFeatures');
        }]) ?? [];

        session(['current_subscription' => $subscription]);
    }
}

function getCurrentSubscription()
{
    // session()->forget('current_subscription');
    if (auth()->check() && auth()->user()->role == 'company') {
        if (!session()->has('current_subscription')) {
            storeCompanyCurrentSubscription();
        }

        return session('current_subscription');
    }
}

function getCurrentSubscriptionFeatures()
{
    return getCurrentSubscription()->plan->planFeatures ?? [];
}

function currencyConversion($amount, $from = null, $to = null, $round = 2)
{
    $from = $from ?? config('kodebazar.currency');
    $to = $to ?? 'USD';

    return Currency::convert()
        ->from($from)
        ->to($to)
        ->amount($amount)
        ->round($round)
        ->get();
}

function checkMailConfig()
{
    $status = config('mail.mailers.smtp.transport') && config('mail.mailers.smtp.host') && config('mail.mailers.smtp.port') && config('mail.mailers.smtp.username') && config('mail.mailers.smtp.password') && config('mail.mailers.smtp.encryption') && config('mail.from.address') && config('mail.from.name');

    return $status ? 1 : 0;
}

function setting($fields = null, $append = false)
{
    if ($fields) {
        $type = gettype($fields);

        if ($type == 'string') {
            $data = $append ? Setting::first($fields) : Setting::value($fields);
        } elseif ($type == 'array') {
            $data = Setting::first($fields);
        }
    } else {
        $data = Setting::first();
    }

    if ($append) {
        $data = $data->makeHidden(['logo_image_url', 'logo_image2_url', 'favicon_image_url']);
    }

    return $data;
}

function sendSms($provider, $to, $message)
{
    if ($provider == 'nexmo') {
        try {
            $basic  = new Basic(config('kodebazar.nexmo_key'), config('kodebazar.nexmo_secret'));
            $client = new NexmoClient($basic);

            $message = $client->message()->send([
                'to' => $to,
                'from' => config('kodebazar.nexmo_from_name'),
                'text' => $message
            ]);
        } catch (Exception $e) {
            dd("Error: " . $e->getMessage());
        }
    } else if ($provider == 'twilio') {
        try {
            $account_sid = config('kodebazar.twilio_secret');
            $auth_token = config('kodebazar.twilio_token');
            $twilio_number = config('kodebazar.twilio_from');

            $client = new TwilioClient($account_sid, $auth_token);
            $client->messages->create($to, [
                'from' => $twilio_number,
                'body' => $message
            ]);
        } catch (Exception $e) {
            dd("Error: " . $e->getMessage());
        }
    }
}

function setEnv($key, $value)
{
    if ($key && $value) {
        $env = new Env();
        $env->setValue($key, $value);
    }

    if (file_exists(App::getCachedConfigPath())) {
        Artisan::call("config:cache");
    }
}

function checkSetEnv($key, $value)
{
    if ((env($key) != $value)) {
        setEnv($key, $value);
    }
}

function metaContent($page)
{
    return Seo::where('page_slug', $page)->first();
}

function importHolidays($company_id, $country_code)
{
    try {
        $holidays = getHolidays($country_code);
    } catch (\Throwable $th) {
        // throw $th;
    }

    if (isset($holidays) && count($holidays)) {
        for ($i = 0; $i < count($holidays); $i++) {
            $holiday_data[] = [
                'company_id' => $company_id,
                'title' => $holidays[$i]['title'],
                'start' => $holidays[$i]['start'],
                'end' => $holidays[$i]['end'],
                'days' => diffBetweenDays($holidays[$i]['start'], $holidays[$i]['end']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $holiday_chunks = array_chunk($holiday_data, ceil(count($holiday_data) / 3));

        foreach ($holiday_chunks as $country) {
            Holiday::insert($country);
        }
    }
}

function diffBetweenDays($start_date, $end_date)
{
    $days = CarbonPeriod::since($start_date)->days(1)->until($end_date);
    return count($days);

    // $start_date = Carbon::parse(date('Y-m-d', strtotime($start_date)));
    // $end_date = Carbon::parse(date('Y-m-d', strtotime($end_date)));

    // return $start_date->diffInDays($end_date);
}

function daysPeriods($start_date, $end_date)
{
    $days_periods = CarbonPeriod::create($start_date, $end_date)->map(fn ($date) => $date->toDateString());
    return iterator_to_array($days_periods);
}

function subDays($date, $days = 1, $format = 'Y-m-d')
{
    return Carbon::parse($date)->subDay($days)->format($format);
}

function formatTime($date, $format = 'Y-m-d')
{
    return Carbon::parse($date)->format($format);
}

function weekly_holidays($company_holidays)
{
    $week_days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    $weekly_holidays = [];

    if ($week_days && $company_holidays) {
        foreach ($week_days as $week_day) {
            if (!$company_holidays->$week_day) {
                $weekly_holidays[] = $week_day;
            }
        }
    }

    return $weekly_holidays;
}

function official_holidays($company_id ,$start_date, $end_date){
    $holidays = [];
    $holidays_between_days = Holiday::where('company_id', $company_id)
            ->whereDate('start', '>=', $start_date)
            ->whereDate('end', '<=', $end_date)
            ->get(['start','end']);

    foreach ($holidays_between_days as $holiday) {
        $holidays = array_merge($holidays, iterator_to_array(CarbonPeriod::create($holiday->start, $holiday->end)->map(fn ($date) => $date->toDateString())));
    }

    $holidays = array_values(array_unique($holidays));

    return $holidays;
}

function sumWeekendDays($days_periods,$weekly_holidays){
    $total_days = 0;

    foreach ($days_periods as $day) {
        $day_name = mb_strtolower(Carbon::parse($day)->format('l'));

        if (in_array($day_name, $weekly_holidays)) {
            $total_days++;
        }
    }

    return $total_days;
}

function sumOfficialHolidays($days_periods,$holidays){
    $total_days = 0;

    foreach ($days_periods as $day) {
        if (in_array($day, $holidays)) {
            $total_days++;
        }
    }

    return $total_days;
}

function sumDaysBetweenDates($company_id, $start_date, $end_date){
    $start_date = $start_date;
    $end_date = $end_date;
    $days_periods = daysPeriods($start_date, $end_date);
    $total_days = count($days_periods);

    // Holidays
    $holidays = official_holidays($company_id,$start_date, $end_date);
    $official_holidays = sumOfficialHolidays($days_periods, $holidays);

    // Weekly Off days
    $company_holidays = WorkingDay::where('company_id', $company_id)->first();
    $weekly_holidays = weekly_holidays($company_holidays);
    $weekend_days = sumWeekendDays($days_periods, $weekly_holidays);

    return [
        'days_count' => $total_days,
        'official_holidays_count' => $official_holidays,
        'weekend_days_count' => $weekend_days,
        'final_days_count' => $total_days - $official_holidays - $weekend_days,
    ];
}

function sumFinalDays($company_id, $start_date, $end_date){
    $start_date = $start_date;
    $end_date = $end_date;
    $days_periods = daysPeriods($start_date, $end_date);
    $total_days = count($days_periods);

    // Holidays
    $holidays = official_holidays($company_id,$start_date, $end_date);
    $official_holidays = sumOfficialHolidays($days_periods, $holidays);

    // Weekly Off days
    $company_holidays = WorkingDay::where('company_id', $company_id)->first();
    $weekly_holidays = weekly_holidays($company_holidays);
    $weekend_days = sumWeekendDays($days_periods, $weekly_holidays);

    return $total_days - $official_holidays - $weekend_days;
}
