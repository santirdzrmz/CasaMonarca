<?php

return [
    /*
    | Visibilidad del contenido del registro por rol (respuestas del API).
    |
    | enforced                  true: el API oculta o bloquea el payload según rol y estado.
    |                           false: comportamiento original (todos los roles autorizados ven todo).
    | operator_payload_statuses Estados en los que el Operador (non_coordinator) puede ver el payload
    |                           de registros AJENOS. Por defecto: su cola de revisión.
    */
    'enforced' => (bool) env('REGISTRY_VISIBILITY_ENFORCED', false),
    'operator_payload_statuses' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('REGISTRY_OPERATOR_PAYLOAD_STATUSES', 'pending_review,changes_requested'))
    ))),
];
