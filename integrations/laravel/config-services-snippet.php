<?php

// Add inside the array returned by config/services.php:

return [
    // ...
    'inquiry' => [
        'url' => env('INQUIRY_API_URL'),
        'key' => env('INQUIRY_API_KEY'),
    ],
];

// routes/web.php:
//   use App\Http\Controllers\InquiryController;
//   Route::get('/contact', [InquiryController::class, 'create']);
//   Route::post('/contact', [InquiryController::class, 'store'])->middleware('throttle:10,1');
//
// routes/console.php (shared hosting without a long-running worker; cron runs schedule:run every minute):
//   use Illuminate\Support\Facades\Schedule;
//   Schedule::command('queue:work --stop-when-empty --tries=8 --max-time=50')->everyMinute()->withoutOverlapping();
