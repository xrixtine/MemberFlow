<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Run locally with:  php artisan schedule:work
| (or trigger once with: php artisan memberflow:expire / memberflow:expire-invitations)
*/

Schedule::command('memberflow:expire')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('memberflow:expire-invitations')->dailyAt('00:10')->withoutOverlapping();
