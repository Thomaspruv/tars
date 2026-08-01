<?php

use App\Http\Controllers\GoogleCalendarOAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/', 'pages::today')->name('today');
    Route::livewire('inbox', 'pages::inbox')->name('inbox.index');
    Route::livewire('objectifs', 'pages::goals')->name('goals.index');
    Route::livewire('objectifs/{goal}', 'pages::goals.show')->name('goals.show');
    Route::livewire('entites', 'pages::entities')->name('entities.index');
    Route::livewire('entites/{entity}', 'pages::entities.show')->name('entities.show');
    Route::livewire('listes', 'pages::lists')->name('lists.index');
    Route::livewire('cerveau', 'pages::brain')->name('brain.index');
    Route::livewire('taches', 'pages::tasks')->name('tasks.index');
    Route::livewire('calendrier', 'pages::calendar')->name('calendar.index');
    Route::livewire('revue', 'pages::review')->name('review.index');
    Route::livewire('bilan-de-vie', 'pages::bilan')->name('bilan.index');
    Route::livewire('bilan-de-vie/questionnaires/{questionnaire}', 'pages::bilan.questionnaire')->name('bilan.questionnaire.edit');
    Route::livewire('bilan-de-vie/runs/{run}', 'pages::bilan.run')->name('bilan.run.fill');
    Route::livewire('agents', 'pages::agents')->name('agents.index');
    Route::livewire('reglages', 'pages::settings-app')->name('settings.index');

    Route::get('reglages/google-calendar/connecter', [GoogleCalendarOAuthController::class, 'redirect'])->name('google-calendar.connect');
    Route::get('reglages/google-calendar/callback', [GoogleCalendarOAuthController::class, 'callback'])->name('google-calendar.callback');
});

require __DIR__.'/settings.php';
