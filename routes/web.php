<?php

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
    Route::livewire('revue', 'pages::review')->name('review.index');
    Route::livewire('agents', 'pages::agents')->name('agents.index');
    Route::livewire('reglages', 'pages::settings-app')->name('settings.index');
});

require __DIR__.'/settings.php';
