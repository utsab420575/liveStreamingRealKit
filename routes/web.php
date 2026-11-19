<?php

use App\Http\Controllers\MeetingController;
use App\Http\Controllers\RealtimeKitController;
use Illuminate\Support\Facades\Route;

Route::pattern('meeting', '[A-Z0-9]{6,10}');

Route::get('/', [MeetingController::class, 'home'])->name('home');

Route::get('/meetings/create', [MeetingController::class, 'create'])->name('meetings.create');
Route::post('/meetings', [MeetingController::class, 'store'])
    ->middleware('throttle:meeting-create')
    ->name('meetings.store');

Route::get('/meetings/join', [MeetingController::class, 'join'])->name('meetings.join');
Route::post('/meetings/resolve', [MeetingController::class, 'resolve'])->name('meetings.resolve');

Route::get('/meetings/{meeting:meeting_code}', [MeetingController::class, 'show'])->name('meetings.show');
Route::post('/meetings/{meeting:meeting_code}/token', [RealtimeKitController::class, 'token'])
    ->middleware('throttle:meeting-token')
    ->name('meetings.token');
Route::post('/meetings/{meeting:meeting_code}/disable', [MeetingController::class, 'disable'])
    ->name('meetings.disable');
