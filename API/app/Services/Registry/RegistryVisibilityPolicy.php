<?php

namespace App\Services\Registry;

/**
 * Qué parte de un registro puede ver cada rol. Lógica pura (sin base de datos) para poder probarla aislada.
 *
 * Niveles:
 *  - full:     metadatos y contenido (payload)
 *  - metadata: estado, fechas, firmas e historial, pero NO el contenido
 *  - none:     el registro no se devuelve
 */
final class RegistryVisibilityPolicy
{
    public const FULL = 'full';

    public const METADATA = 'metadata';

    public const NONE = 'none';

    /** @param list<string> $operatorPayloadStatuses */
    public function level(string $role, string $status, bool $isOwner, array $operatorPayloadStatuses): string
    {
        if ($status === MigrantRegistryService::STATUS_DRAFT) {
            return $isOwner ? self::FULL : self::NONE;
        }

        return match ($role) {
            'admin', 'coordinator' => self::FULL,
            // Maker-checker: el Operador revisa lo ajeno que está en su cola; nunca lo que él mismo registró.
            'non_coordinator' => (! $isOwner && in_array($status, $operatorPayloadStatuses, true)) ? self::FULL : self::METADATA,
            // El Voluntario registra y solo ve el estado de lo suyo.
            'volunteer' => $isOwner ? self::METADATA : self::NONE,
            default => self::NONE,
        };
    }
}
