<?php

namespace App\Enums;

enum Department: string
{
    case Humanitarian = 'humanitarian';
    case Psychosocial = 'psychosocial';
    case Legal = 'legal';
    case Communication = 'communication';
    case Warehouse = 'warehouse';
    case InformationTechnology = 'information_technology';

    public function label(): string
    {
        return match ($this) {
            self::Humanitarian => 'Humanitaria',
            self::Psychosocial => 'Psicosocial',
            self::Legal => 'Legal',
            self::Communication => 'Comunicación',
            self::Warehouse => 'Almacén',
            self::InformationTechnology => 'Tecnologías de información',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
