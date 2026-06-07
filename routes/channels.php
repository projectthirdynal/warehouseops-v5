<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('agent.{agentId}', function ($user, $agentId) {
    return (int) $user->id === (int) $agentId;
});

Broadcast::channel('supervisor.leads', function ($user) {
    return in_array($user->role, ['superadmin', 'admin', 'supervisor']);
});
