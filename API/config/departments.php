<?php

return [
    /*
    | Department attribution for modifying requests.
    |
    | enforced  true: every POST/PUT/PATCH/DELETE on protected routes must send
    |           an X-Acting-Department header naming one of the actor's assigned
    |           departments. false: the header is optional (recorded when sent),
    |           but a declared department must still be one the actor holds.
    |           Use false only during rollout, while departments are assigned.
    */
    'enforced' => (bool) env('DEPARTMENT_ATTRIBUTION_ENFORCED', true),
];
