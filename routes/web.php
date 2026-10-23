<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AiContentController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DriveFolderController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PostingRuleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('health');

// Public pages required by Meta App Review and Google OAuth verification.
Route::view('privacy', 'legal.privacy')->name('legal.privacy');
Route::view('terms', 'legal.terms')->name('legal.terms');
Route::view('data-deletion', 'legal.data-deletion')->name('legal.data-deletion');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->middleware('throttle:login');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('account', [AccountController::class, 'edit'])->name('account');
    Route::put('account/password', [AccountController::class, 'updatePassword'])->middleware('throttle:10,1')->name('account.password');
    Route::get('/', DashboardController::class)->name('dashboard');

    // Video library & content studio
    Route::get('videos', [VideoController::class, 'index'])->name('videos.index');
    Route::post('videos/bulk', [VideoController::class, 'bulk'])->name('videos.bulk');
    Route::get('videos/{video}', [VideoController::class, 'show'])->name('videos.show');
    Route::patch('videos/{video}', [VideoController::class, 'update'])->name('videos.update');
    Route::get('videos/{video}/media', [VideoController::class, 'media'])->name('videos.media');
    Route::get('videos/{video}/thumbnail', [VideoController::class, 'thumbnail'])->name('videos.thumbnail');
    Route::post('videos/{video}/reprocess', [VideoController::class, 'reprocess'])->name('videos.reprocess');
    Route::post('videos/{video}/generate', [VideoController::class, 'generate'])->name('videos.generate');
    Route::put('videos/{video}/content/{platform}', [AiContentController::class, 'update'])->whereIn('platform', ['facebook', 'instagram', 'youtube'])->name('content.update');
    Route::post('videos/{video}/content/{platform}/approve', [AiContentController::class, 'approve'])->whereIn('platform', ['facebook', 'instagram', 'youtube'])->name('content.approve');
    Route::post('videos/{video}/content/{platform}/reject', [AiContentController::class, 'reject'])->whereIn('platform', ['facebook', 'instagram', 'youtube'])->name('content.reject');
    Route::post('videos/{video}/schedule', [PostController::class, 'store'])->name('videos.schedule');

    // Publishing
    Route::get('calendar', CalendarController::class)->name('calendar');
    Route::get('posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('posts/failed', [PostController::class, 'failed'])->name('posts.failed');
    Route::get('posts/{post}', [PostController::class, 'show'])->name('posts.show');
    Route::patch('posts/{post}/reschedule', [PostController::class, 'reschedule'])->name('posts.reschedule');
    Route::post('posts/{post}/cancel', [PostController::class, 'cancel'])->name('posts.cancel');
    Route::post('posts/{post}/retry', [PostController::class, 'retry'])->name('posts.retry');
    Route::post('posts/{post}/confirm', [PostController::class, 'confirmPublished'])->name('posts.confirm');

    // Accounts & rules
    Route::get('accounts', [SocialAccountController::class, 'index'])->name('accounts.index');
    Route::get('accounts/meta/connect', [SocialAccountController::class, 'connectMeta'])->name('accounts.meta.connect');
    Route::get('oauth/meta/callback', [SocialAccountController::class, 'metaCallback'])->name('accounts.meta.callback');
    Route::get('accounts/meta/select/{key}', [SocialAccountController::class, 'metaSelect'])->name('accounts.meta.select');
    Route::post('accounts/meta/select/{key}', [SocialAccountController::class, 'metaStore'])->name('accounts.meta.store');
    Route::get('accounts/google/connect', [SocialAccountController::class, 'connectGoogle'])->name('accounts.google.connect');
    Route::get('oauth/google/callback', [SocialAccountController::class, 'googleCallback'])->name('accounts.google.callback');
    Route::post('accounts/{account}/pause', [SocialAccountController::class, 'pause'])->name('accounts.pause');
    Route::post('accounts/{account}/resume', [SocialAccountController::class, 'resume'])->name('accounts.resume');
    Route::post('accounts/{account}/retry-failed', [SocialAccountController::class, 'retryFailed'])->name('accounts.retry-failed');
    Route::delete('accounts/{account}', [SocialAccountController::class, 'disconnect'])->name('accounts.disconnect');

    Route::get('rules', [PostingRuleController::class, 'index'])->name('rules.index');
    Route::post('rules/global', [PostingRuleController::class, 'global'])->name('rules.global');
    Route::get('rules/{account}', [PostingRuleController::class, 'edit'])->name('rules.edit');
    Route::put('rules/{account}', [PostingRuleController::class, 'update'])->name('rules.update');

    // Devices, settings, analytics, logs
    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke'])->name('devices.revoke');

    Route::get('drive', [DriveFolderController::class, 'index'])->name('drive.index');
    Route::post('drive', [DriveFolderController::class, 'store'])->name('drive.store');
    Route::post('drive/{folder}/toggle', [DriveFolderController::class, 'toggle'])->name('drive.toggle');
    Route::post('drive/{folder}/refresh', [DriveFolderController::class, 'refresh'])->name('drive.refresh');
    Route::delete('drive/{folder}', [DriveFolderController::class, 'destroy'])->name('drive.destroy');

    Route::get('settings/email', [SettingsController::class, 'email'])->name('settings.email');
    Route::put('settings/email', [SettingsController::class, 'updateEmail'])->name('settings.email.update');
    Route::post('settings/email/test', [SettingsController::class, 'testEmail'])->middleware('throttle:5,1')->name('settings.email.test');
    Route::post('settings/email/validate', [SettingsController::class, 'validateSmtp'])->middleware('throttle:5,1')->name('settings.email.validate');
    Route::get('settings/ai', [SettingsController::class, 'ai'])->name('settings.ai');
    Route::put('settings/ai', [SettingsController::class, 'updateAi'])->name('settings.ai.update');

    Route::get('analytics', AnalyticsController::class)->name('analytics');
    Route::get('logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('logs/failed-jobs', [LogController::class, 'failedJobs'])->name('logs.failed-jobs');
    Route::post('logs/failed-jobs/{uuid}/retry', [LogController::class, 'retryJob'])->whereUuid('uuid')->name('logs.failed-jobs.retry');
    Route::delete('logs/failed-jobs/{uuid}', [LogController::class, 'forgetJob'])->whereUuid('uuid')->name('logs.failed-jobs.forget');
});
